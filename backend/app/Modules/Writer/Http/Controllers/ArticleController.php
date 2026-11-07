<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Actions\ArticleWorkflows;
use SiteXPost\Modules\Writer\Actions\CreateArticle;
use SiteXPost\Modules\Writer\Actions\Editorial;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleOutline;
use SiteXPost\Modules\Writer\Domain\ArticleWorkflowRun;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Enums\ArticleStatus;
use SiteXPost\Modules\Writer\Http\Idempotency;
use SiteXPost\Modules\Writer\Http\Requests\StartWorkflowRequest;
use SiteXPost\Modules\Writer\Http\Requests\StoreArticleRequest;
use SiteXPost\Modules\Writer\Http\Requests\UpdateArticleRequest;
use SiteXPost\Modules\Writer\Http\Resources\ArticleResource;
use SiteXPost\Modules\Writer\Http\WriterPresenter;
use SiteXPost\Modules\Writer\Pipeline\AutoApprover;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Article list / wizard create / detail with live workflow progress /
 * settings / cancel, plus starting, resuming and cancelling AI workflows.
 * Every AI start answers 202 with the job reference; the work runs on the
 * queue.
 */
final class ArticleController
{
    /** Which statuses each workflow may be started from (user-initiated). */
    private const START_FROM = [
        'research' => [ArticleStatus::IdeaSelected, ArticleStatus::ResearchComplete, ArticleStatus::BriefReady],
        'outline' => [ArticleStatus::BriefReady, ArticleStatus::Outlining],
        'draft' => [ArticleStatus::Outlining],
        'recheck' => [ArticleStatus::NeedsHumanReview, ArticleStatus::Approved],
    ];

    public function __construct(private readonly ArticleWorkflows $workflows) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', Article::class);
        $q = Article::query();
        $status = $request->query('status');
        if (is_string($status) && ArticleStatus::tryFrom($status) !== null) {
            $q->where('status', $status);
        } elseif ($status === 'active') {
            $q->whereNotIn('status', ['APPROVED', 'CANCELLED']);
        }
        foreach (['site_id', 'campaign_id', 'writing_batch_id'] as $f) {
            $v = $request->query($f);
            if (is_string($v) && preg_match('/^[0-9a-z]{26}$/i', $v) === 1) {
                $q->where($f, $v);
            }
        }
        $type = $request->query('content_type');
        if (is_string($type) && in_array($type, CreateArticle::CONTENT_TYPES, true)) {
            $q->where('content_type', $type);
        }
        if ($request->query('needs_review') === '1') {
            $q->where('status', 'NEEDS_HUMAN_REVIEW');
        }
        $search = $request->query('q');
        if (is_string($search) && trim($search) !== '') {
            $q->where('title', 'like', '%'.addcslashes(mb_substr(trim($search), 0, 100), '%_\\').'%');
        }
        $dir = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        match ($request->query('sort')) {
            'title' => $q->orderBy('title', $dir)->orderBy('id', $dir),
            'quality' => $q->orderBy('quality_score', $dir)->orderBy('id', $dir),
            default => $q->orderBy('updated_at', $dir)->orderBy('id', $dir),
        };

        return Paginated::response($q, ArticleResource::class, $request, 25);
    }

    public function store(StoreArticleRequest $request, Workspace $workspace, CreateArticle $create): JsonResponse
    {
        Gate::authorize('create', Article::class);
        $key = Idempotency::key($request);
        $article = $create->handle($request->validated(), (string) $request->user()?->getAuthIdentifier(), $key);

        return new JsonResponse(['data' => $this->detail($request, $article)], $article->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);
        $this->workflows->reconcile($article);

        return new JsonResponse(['data' => $this->detail($request, $article->refresh())]);
    }

    public function update(UpdateArticleRequest $request, Workspace $workspace, Article $article, WriterSettings $settings): JsonResponse
    {
        Gate::authorize('operate', $article);
        $data = $request->validated();
        if (isset($data['budget_limit_micro_usd'])) {
            $max = $settings->int('max_article_budget_micro_usd', $article->workspace_id);
            if ($data['budget_limit_micro_usd'] > $max) {
                throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['budget_limit_micro_usd' => [__('writer.errors.budget_too_high', ['max' => $max / 1_000_000])]]);
            }
            if ($data['budget_limit_micro_usd'] < $article->spent_micro_usd) {
                throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['budget_limit_micro_usd' => [__('writer.errors.budget_below_spent')]]);
            }
        }
        if (array_key_exists('writing_preset_id', $data) && $data['writing_preset_id'] !== null && ! WritingPreset::query()->whereKey($data['writing_preset_id'])->exists()) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['writing_preset_id' => [__('writer.errors.preset_not_found')]]);
        }
        if (isset($data['title']) && $article->current_version_id !== null) {
            unset($data['title']); // the title of written content changes through a new version only
        }
        if (array_key_exists('approval_mode', $data)) {
            AutoApprover::assertAllowed($settings, $article->workspace_id, $data['approval_mode'], 'approval_mode');
            $article->forceFill(['settings' => array_merge($article->settings ?? [], ['approval_mode' => $data['approval_mode']])]);
            unset($data['approval_mode']);
        }
        $research = array_intersect_key($data, array_flip(['source_urls', 'allow_web_search', 'ai_source_discovery']));
        if ($research !== []) {
            if (isset($research['source_urls'])) {
                $research['source_urls'] = array_values(array_unique(array_map('trim', $research['source_urls'])));
            }
            $article->forceFill(['settings' => array_merge($article->settings ?? [], $research)]);
            $data = array_diff_key($data, $research);
        }
        $before = $article->only(array_keys($data));
        $article->forceFill($data);
        if (isset($data['budget_limit_micro_usd']) && $article->blocked_reason === 'article_budget_exhausted' && $article->remainingBudget() > 0) {
            $article->blocked_reason = null;
        }
        $article->save();
        Audit::record('writer.article_updated', $article, ['before' => $before, 'after' => $data + ($research !== [] ? ['research' => $research] : [])]);

        return new JsonResponse(['data' => $this->detail($request, $article)]);
    }

    public function cancel(Request $request, Workspace $workspace, Article $article, Editorial $editorial): JsonResponse
    {
        Gate::authorize('operate', $article);
        $editorial->cancelArticle($article, (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => $this->detail($request, $article->refresh())]);
    }

    public function startWorkflow(StartWorkflowRequest $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('operate', $article);
        $kind = (string) $request->validated('kind');
        $this->workflows->reconcile($article);
        if (! in_array($article->refresh()->status, self::START_FROM[$kind], true)) {
            throw ApiException::conflict('invalid_state_transition', __('writer.errors.cannot_start', ['kind' => $kind, 'status' => $article->status->value]));
        }
        if ($kind === 'outline' && ! ContentBrief::query()->where('article_id', $article->id)->where('status', 'approved')->exists()) {
            throw ApiException::conflict('brief_not_approved', __('writer.errors.brief_not_approved'));
        }
        if ($kind === 'draft' && ! ArticleOutline::query()->where('article_id', $article->id)->where('status', 'approved')->exists()) {
            throw ApiException::conflict('outline_not_approved', __('writer.errors.outline_not_approved'));
        }
        $extra = $kind === 'recheck' ? ['full' => $request->boolean('full'), 'auto_revise' => $request->boolean('auto_revise')] : [];
        $run = $this->workflows->start($article, $kind, (string) $request->user()?->getAuthIdentifier(), Idempotency::key($request), $extra);
        Audit::record('writer.workflow_started', $article, ['kind' => $kind, 'workflow_run_id' => $run->id]);

        return $this->accepted($request, $article, $run);
    }

    public function resume(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('operate', $article);
        $this->workflows->reconcile($article);
        $run = $this->workflows->resume($article->refresh(), (string) $request->user()?->getAuthIdentifier());

        return $this->accepted($request, $article, $run);
    }

    public function cancelWorkflow(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('operate', $article);
        if (! $this->workflows->cancel($article, (string) $request->user()?->getAuthIdentifier())) {
            throw ApiException::conflict('nothing_to_cancel', __('writer.errors.nothing_to_cancel'));
        }

        return new JsonResponse(['data' => $this->detail($request, $article->refresh())]);
    }

    public function workflows(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);
        $links = ArticleWorkflowRun::query()->where('article_id', $article->id)->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get();
        $runs = WorkflowRun::query()->with('steps')->whereIn('id', $links->pluck('workflow_run_id'))->get()->keyBy('id');

        return new JsonResponse(['data' => $links->map(fn (ArticleWorkflowRun $l) => WriterPresenter::workflow($l, $runs->get($l->workflow_run_id)))->filter()->values()]);
    }

    private function accepted(Request $request, Article $article, WorkflowRun $run): JsonResponse
    {
        return new JsonResponse(['data' => [
            'article' => $this->detail($request, $article->refresh()),
            'job_run' => ['id' => $run->job_run_id, 'workflow_run_id' => $run->id, 'status' => $run->status->value, 'type' => $run->type],
        ]], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Request $request, Article $article): array
    {
        $link = ArticleWorkflowRun::query()->where('article_id', $article->id)->orderByDesc('created_at')->orderByDesc('id')->first();
        $run = $link !== null ? WorkflowRun::query()->with('steps')->find($link->workflow_run_id) : null;
        $brief = ContentBrief::query()->where('article_id', $article->id)->orderByDesc('version')->first();
        $outline = ArticleOutline::query()->where('article_id', $article->id)->orderByDesc('version')->first();

        return (new ArticleResource($article))->resolve($request) + [
            'workflow' => WriterPresenter::workflow($link, $run),
            'brief' => $brief !== null ? ['id' => $brief->id, 'version' => $brief->version, 'status' => $brief->status] : null,
            'outline' => $outline !== null ? ['id' => $outline->id, 'version' => $outline->version, 'status' => $outline->status] : null,
            'waiting_for' => match (true) {
                in_array($article->status, [ArticleStatus::BriefReady, ArticleStatus::ResearchComplete], true) && $brief?->status !== 'approved' && ! $run?->status->isActive() => 'brief_approval',
                $article->status === ArticleStatus::Outlining && $outline !== null && $outline->status !== 'approved' && ! $run?->status->isActive() => 'outline_approval',
                $article->status === ArticleStatus::NeedsHumanReview => 'human_review',
                default => null,
            },
        ];
    }
}
