<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Actions\Editorial;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleRevision;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Domain\ResearchDossier;
use SiteXPost\Modules\Writer\Http\Idempotency;
use SiteXPost\Modules\Writer\Http\Requests\AutosaveRequest;
use SiteXPost\Modules\Writer\Http\Requests\RewriteRequest;
use SiteXPost\Modules\Writer\Http\Requests\SaveVersionRequest;
use SiteXPost\Modules\Writer\Http\WriterPresenter;
use SiteXPost\Modules\Writer\Support\ArticleDocument;
use SiteXPost\Modules\Writer\Support\HtmlSanitizer;
use SiteXPost\Modules\Writer\Support\TextDiff;
use SiteXPost\Modules\Writer\Workflows\RewriteWorkflow;

/**
 * Article Editor: current content (+ autosave draft), save as version,
 * autosave, version history, diff, restore, AI rewrite proposals
 * (request / apply / discard).
 */
final class ArticleContentController
{
    public function editor(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);
        $v = $article->current_version_id !== null ? ArticleVersion::query()->find($article->current_version_id) : null;
        $dossierIds = ResearchDossier::query()->where('article_id', $article->id)->pluck('id');
        $citations = EvidenceItem::query()->with('source:id,url,final_url,title,source_type,reliability_score')->whereIn('research_dossier_id', $dossierIds)
            ->orderBy('ref')->limit(300)->get()
            ->map(fn (EvidenceItem $e) => ['id' => $e->id, 'ref' => $e->ref, 'statement' => $e->statement, 'url' => $e->source->final_url ?? $e->source?->url,
                'title' => $e->source?->title, 'source_type' => $e->source?->source_type, 'reliability_score' => $e->source?->reliability_score])->all();
        $autosave = $article->autosaved_at !== null && $article->autosave_base_version_id === $article->current_version_id
            ? ['content_html' => $article->autosave_html, 'base_version_id' => $article->autosave_base_version_id, 'autosaved_at' => $article->autosaved_at->toIso8601ZuluString()]
            : null;

        return new JsonResponse(['data' => [
            'version' => $v !== null ? WriterPresenter::version($v) : null,
            'lock_version' => $article->lock_version,
            'autosave' => $autosave,
            'citations' => $citations,
            'rewrite_actions' => array_keys(RewriteWorkflow::ACTIONS),
        ]]);
    }

    public function versions(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);

        return WriterPresenter::page(ArticleVersion::query()->where('article_id', $article->id)->orderByDesc('number')->orderByDesc('id'),
            fn (ArticleVersion $v) => WriterPresenter::version($v, false), $request, 30);
    }

    public function version(Request $request, Workspace $workspace, Article $article, ArticleVersion $version): JsonResponse
    {
        Gate::authorize('view', $article);

        return new JsonResponse(['data' => WriterPresenter::version($version)]);
    }

    public function diff(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);
        $find = function (mixed $id) use ($article): ArticleVersion {
            if (! is_string($id) || preg_match('/^[0-9a-z]{26}$/i', $id) !== 1) {
                throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['from' => ['Version id required.']]);
            }

            return ArticleVersion::query()->where('article_id', $article->id)->whereKey($id)->first() ?? throw ApiException::notFound();
        };
        $from = $find($request->query('from'));
        $to = $find($request->query('to', $article->current_version_id));
        $a = ArticleDocument::split($from->content_html, $from->section_hashes ?? []);
        $b = ArticleDocument::split($to->content_html, [...($to->section_hashes ?? []), ...($from->section_hashes ?? [])]);
        $sections = [];
        $byKey = collect($a)->keyBy('key');
        foreach ($b as $s) {
            $old = $byKey->pull($s['key']);
            if ($old !== null && $old['hash'] === $s['hash']) {
                continue;
            }
            $sections[] = ['key' => $s['key'], 'heading' => $s['heading'], 'change' => $old === null ? 'added' : 'modified',
                'ops' => array_slice(TextDiff::compact(TextDiff::diff($old['text'] ?? '', $s['text'])), 0, 300)];
        }
        foreach ($byKey as $old) {
            $sections[] = ['key' => $old['key'], 'heading' => $old['heading'], 'change' => 'removed', 'ops' => [['op' => 'del', 'text' => mb_substr($old['text'], 0, 4000)]]];
        }

        return new JsonResponse(['data' => [
            'from' => WriterPresenter::version($from, false), 'to' => WriterPresenter::version($to, false),
            'change_ratio' => round(TextDiff::changeRatio(HtmlSanitizer::text($from->content_html), HtmlSanitizer::text($to->content_html)), 3),
            'sections' => $sections,
        ]]);
    }

    public function save(SaveVersionRequest $request, Workspace $workspace, Article $article, Editorial $editorial): JsonResponse
    {
        Gate::authorize('operate', $article);
        $before = $article->current_version_id;
        $v = $editorial->save($article, (string) $request->validated('content_html'), (string) $request->validated('base_version_id'),
            (string) $request->user()?->getAuthIdentifier(), $request->validated('summary'));

        return new JsonResponse(['data' => ['version' => WriterPresenter::version($v), 'lock_version' => $article->refresh()->lock_version,
            'status' => $article->status->value, 'unchanged' => $v->id === $before]], $v->id === $before ? 200 : 201);
    }

    public function autosave(AutosaveRequest $request, Workspace $workspace, Article $article, Editorial $editorial): JsonResponse
    {
        Gate::authorize('operate', $article);

        return new JsonResponse(['data' => $editorial->autosave($article, (string) $request->validated('content_html'),
            (string) $request->validated('base_version_id'), (int) $request->validated('lock_version'))]);
    }

    public function restore(Request $request, Workspace $workspace, Article $article, ArticleVersion $version, Editorial $editorial): JsonResponse
    {
        Gate::authorize('operate', $article);
        $v = $editorial->restore($article, $version, (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => ['version' => WriterPresenter::version($v), 'lock_version' => $article->refresh()->lock_version]], 201);
    }

    public function revisions(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);
        $q = ArticleRevision::query()->where('article_id', $article->id);
        $kind = $request->query('kind');
        if (in_array($kind, ['editor', 'auto_fix'], true)) {
            $q->where('kind', $kind);
        }
        $status = $request->query('status');
        if (is_string($status) && in_array($status, ['queued', 'proposed', 'applied', 'discarded', 'failed', 'stale', 'rejected'], true)) {
            $q->where('status', $status);
        }

        return WriterPresenter::page($q->orderByDesc('created_at')->orderByDesc('id'), fn (ArticleRevision $r) => WriterPresenter::revision($r), $request, 20);
    }

    public function rewrite(RewriteRequest $request, Workspace $workspace, Article $article, Editorial $editorial): JsonResponse
    {
        Gate::authorize('operate', $article);
        $rev = $editorial->requestRewrite($article, $request->validated(), (string) $request->user()?->getAuthIdentifier(), Idempotency::key($request));

        return new JsonResponse(['data' => WriterPresenter::revision($rev->refresh())], 202);
    }

    public function revision(Request $request, Workspace $workspace, Article $article, ArticleRevision $revision): JsonResponse
    {
        Gate::authorize('view', $article);

        return new JsonResponse(['data' => WriterPresenter::revision($revision)]);
    }

    public function applyRevision(Request $request, Workspace $workspace, Article $article, ArticleRevision $revision, Editorial $editorial): JsonResponse
    {
        Gate::authorize('operate', $article);
        $v = $editorial->applyRevision($article, $revision, (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => ['version' => WriterPresenter::version($v), 'lock_version' => $article->refresh()->lock_version]], 201);
    }

    public function discardRevision(Request $request, Workspace $workspace, Article $article, ArticleRevision $revision, Editorial $editorial): JsonResponse
    {
        Gate::authorize('operate', $article);
        $editorial->discardRevision($article, $revision);

        return new JsonResponse(['data' => WriterPresenter::revision($revision->refresh())]);
    }
}
