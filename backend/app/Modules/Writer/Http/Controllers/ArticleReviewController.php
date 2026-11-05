<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Actions\Editorial;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleAiUsage;
use SiteXPost\Modules\Writer\Domain\ArticleClaim;
use SiteXPost\Modules\Writer\Domain\EditorialApproval;
use SiteXPost\Modules\Writer\Domain\FactCheckResult;
use SiteXPost\Modules\Writer\Domain\FactCheckRun;
use SiteXPost\Modules\Writer\Domain\QualityReport;
use SiteXPost\Modules\Writer\Enums\Verdict;
use SiteXPost\Modules\Writer\Http\Requests\ApproveArticleRequest;
use SiteXPost\Modules\Writer\Http\Requests\DuplicateDecisionRequest;
use SiteXPost\Modules\Writer\Http\Requests\RejectArticleRequest;
use SiteXPost\Modules\Writer\Http\Requests\VerifyClaimRequest;
use SiteXPost\Modules\Writer\Http\Resources\ArticleResource;
use SiteXPost\Modules\Writer\Http\WriterPresenter;

/**
 * Fact-Check Review (claims, verdicts, evidence, manual verification),
 * quality reports, approval checklist, approve / request changes,
 * duplicate decision and per-article AI cost.
 */
final class ArticleReviewController
{
    public function claims(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);
        $versionId = $request->query('version_id', $article->current_version_id);
        $q = ArticleClaim::query()->with('latestResult')->where('article_id', $article->id)->where('article_version_id', is_string($versionId) ? $versionId : '');
        foreach (['status' => ['PENDING', 'VERIFIED', 'CONTRADICTED', 'INSUFFICIENT_EVIDENCE', 'OUTDATED', 'NEEDS_HUMAN_REVIEW'], 'risk_level' => ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']] as $f => $allowed) {
            $v = $request->query($f);
            if (is_string($v) && in_array($v, $allowed, true)) {
                $q->where($f, $v);
            }
        }
        $section = $request->query('section_key');
        if (is_string($section) && $section !== '') {
            $q->where('section_key', mb_substr($section, 0, 40));
        }
        if ($request->query('unresolved') === '1') {
            $q->where('status', '!=', 'VERIFIED');
        }
        $q->orderByRaw("CASE risk_level WHEN 'CRITICAL' THEN 0 WHEN 'HIGH' THEN 1 WHEN 'MEDIUM' THEN 2 ELSE 3 END")->orderBy('id');

        return WriterPresenter::page($q, fn (ArticleClaim $c) => WriterPresenter::claim($c), $request, 50);
    }

    public function claim(Request $request, Workspace $workspace, Article $article, ArticleClaim $claim): JsonResponse
    {
        Gate::authorize('view', $article);
        $claim->load('latestResult');
        $history = FactCheckResult::query()->where('article_claim_id', $claim->id)->orderByDesc('reviewed_at')->orderByDesc('id')->limit(20)->get();

        return new JsonResponse(['data' => WriterPresenter::claim($claim) + [
            'history' => $history->map(fn ($r) => WriterPresenter::result($r))->all(),
        ]]);
    }

    public function verifyClaim(VerifyClaimRequest $request, Workspace $workspace, Article $article, ArticleClaim $claim, Editorial $editorial): JsonResponse
    {
        // CRITICAL claims (legal, safety...) need an approver; others an editor.
        Gate::authorize($claim->risk_level === 'CRITICAL' ? 'approve' : 'operate', $article);
        $editorial->verifyClaim($article, $claim, Verdict::from((string) $request->validated('verdict')), (string) $request->validated('note'),
            array_values((array) ($request->validated('evidence_ids') ?? [])), $request->validated('reference_url'), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => WriterPresenter::claim($claim->refresh()->load('latestResult')) + ['article' => [
            'unresolved_critical' => $article->refresh()->unresolved_critical, 'unresolved_high' => $article->unresolved_high, 'status' => $article->status->value,
        ]]]);
    }

    public function factChecks(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);

        return WriterPresenter::page(FactCheckRun::query()->where('article_id', $article->id)->orderByDesc('created_at')->orderByDesc('id'),
            fn (FactCheckRun $r) => WriterPresenter::factCheckRun($r), $request, 20);
    }

    public function quality(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);
        $reports = QualityReport::query()->where('article_id', $article->id)->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get();

        return new JsonResponse(['data' => [
            'current' => ($cur = $reports->firstWhere('article_version_id', $article->current_version_id)) !== null ? WriterPresenter::quality($cur) : null,
            'history' => $reports->map(fn ($r) => ['id' => $r->id, 'version_id' => $r->article_version_id, 'score' => $r->score, 'created_at' => $r->created_at?->toIso8601ZuluString()])->all(),
        ]]);
    }

    public function audit(Request $request, Workspace $workspace, Article $article, Editorial $editorial): JsonResponse
    {
        Gate::authorize('operate', $article);

        return new JsonResponse(['data' => WriterPresenter::quality($editorial->audit($article))], 201);
    }

    public function checklist(Request $request, Workspace $workspace, Article $article, Editorial $editorial): JsonResponse
    {
        Gate::authorize('view', $article);
        $r = $editorial->checklist($article, $request->query('acknowledge_high_risk') === '1');

        return new JsonResponse(['data' => $r + [
            'can_approve' => Gate::allows('approve', $article),
            'approvals' => EditorialApproval::query()->where('article_id', $article->id)->orderByDesc('created_at')->limit(20)->get()->map(fn ($a) => WriterPresenter::approval($a))->all(),
        ]]);
    }

    public function approve(ApproveArticleRequest $request, Workspace $workspace, Article $article, Editorial $editorial): JsonResponse
    {
        Gate::authorize('approve', $article);
        $ap = $editorial->approve($article, (string) $request->validated('version_id'), $request->boolean('acknowledge_high_risk'),
            $request->validated('note'), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => ['approval' => WriterPresenter::approval($ap), 'article' => (new ArticleResource($article->refresh()))->resolve($request)]]);
    }

    public function reject(RejectArticleRequest $request, Workspace $workspace, Article $article, Editorial $editorial): JsonResponse
    {
        Gate::authorize('operate', $article);
        $ap = $editorial->reject($article, (string) $request->validated('note'), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => ['approval' => WriterPresenter::approval($ap), 'article' => (new ArticleResource($article->refresh()))->resolve($request)]]);
    }

    public function duplicateDecision(DuplicateDecisionRequest $request, Workspace $workspace, Article $article, Editorial $editorial): JsonResponse
    {
        Gate::authorize('operate', $article);
        $a = $editorial->duplicateDecision($article, (string) $request->validated('decision'), $request->validated('note'), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => (new ArticleResource($a))->resolve($request)]);
    }

    public function usage(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);
        $rows = ArticleAiUsage::query()->where('article_id', $article->id)
            ->selectRaw('step, task_key, model, count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(cost_micro_usd) as cost_micro_usd, sum(fetches) as fetches, sum(case when status = ? then 1 else 0 end) as failed', ['failed'])
            ->groupBy('step', 'task_key', 'model')->orderBy('step')->get()
            ->map(fn ($r) => ['step' => $r->step, 'task_key' => $r->task_key, 'model' => $r->model, 'calls' => (int) $r->calls, 'input_tokens' => (int) $r->input_tokens,
                'output_tokens' => (int) $r->output_tokens, 'cost_micro_usd' => (int) $r->cost_micro_usd, 'fetches' => (int) $r->fetches, 'failed' => (int) $r->failed])->all();

        return new JsonResponse(['data' => [
            'budget_limit_micro_usd' => $article->budget_limit_micro_usd, 'spent_micro_usd' => $article->spent_micro_usd,
            'remaining_micro_usd' => $article->remainingBudget(), 'blocked_reason' => $article->blocked_reason, 'breakdown' => $rows,
        ]]);
    }
}
