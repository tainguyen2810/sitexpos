<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Actions\Editorial;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleOutline;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\EvidenceItem;
use SiteXPost\Modules\Writer\Domain\EvidenceSource;
use SiteXPost\Modules\Writer\Domain\ResearchDossier;
use SiteXPost\Modules\Writer\Domain\ResearchQuestion;
use SiteXPost\Modules\Writer\Http\Requests\ApproveBriefRequest;
use SiteXPost\Modules\Writer\Http\Requests\ApproveOutlineRequest;
use SiteXPost\Modules\Writer\Http\WriterPresenter;

/**
 * Evidence Research view (dossier, questions, sources with status and
 * reliability, evidence excerpts), Content Brief and Outline review /
 * edit / approve.
 */
final class ArticleResearchController
{
    public function dossier(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);
        $d = ResearchDossier::query()->where('article_id', $article->id)->latest()->orderByDesc('id')->first();
        if ($d === null) {
            return new JsonResponse(['data' => null]);
        }

        return new JsonResponse(['data' => WriterPresenter::dossier($d) + [
            'questions' => ResearchQuestion::query()->where('research_dossier_id', $d->id)->orderBy('position')->get()->map(fn ($q) => WriterPresenter::question($q))->all(),
            'sources' => EvidenceSource::query()->where('research_dossier_id', $d->id)->orderByDesc('reliability_score')->orderBy('created_at')->limit(100)->get()
                ->map(fn ($s) => WriterPresenter::source($s))->all(),
        ]]);
    }

    public function evidence(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);
        $dossierIds = ResearchDossier::query()->where('article_id', $article->id)->pluck('id');
        $q = EvidenceItem::query()->with('source')->whereIn('research_dossier_id', $dossierIds);
        $source = $request->query('source_id');
        if (is_string($source) && preg_match('/^[0-9a-z]{26}$/i', $source) === 1) {
            $q->where('evidence_source_id', $source);
        }
        $search = $request->query('q');
        if (is_string($search) && trim($search) !== '') {
            $q->where('statement', 'like', '%'.addcslashes(mb_substr(trim($search), 0, 100), '%_\\').'%');
        }

        return WriterPresenter::page($q->orderBy('ref')->orderBy('id'), fn (EvidenceItem $e) => WriterPresenter::evidence($e), $request, 50);
    }

    public function brief(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);
        $briefs = ContentBrief::query()->where('article_id', $article->id)->orderByDesc('version')->limit(20)->get();

        return new JsonResponse(['data' => [
            'current' => $briefs->first() !== null ? WriterPresenter::brief($briefs->first()) : null,
            'history' => $briefs->map(fn ($b) => ['id' => $b->id, 'version' => $b->version, 'status' => $b->status, 'created_at' => $b->created_at?->toIso8601ZuluString()])->all(),
        ]]);
    }

    public function approveBrief(ApproveBriefRequest $request, Workspace $workspace, Article $article, Editorial $editorial): JsonResponse
    {
        Gate::authorize('operate', $article);
        $data = $request->validated('data');
        $brief = $editorial->approveBrief($article, is_array($data) ? $data : null, (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => WriterPresenter::brief($brief)], 202);
    }

    public function outline(Request $request, Workspace $workspace, Article $article): JsonResponse
    {
        Gate::authorize('view', $article);
        $outlines = ArticleOutline::query()->where('article_id', $article->id)->orderByDesc('version')->limit(20)->get();

        return new JsonResponse(['data' => [
            'current' => $outlines->first() !== null ? WriterPresenter::outline($outlines->first()) : null,
            'history' => $outlines->map(fn ($o) => ['id' => $o->id, 'version' => $o->version, 'status' => $o->status, 'created_at' => $o->created_at?->toIso8601ZuluString()])->all(),
        ]]);
    }

    public function approveOutline(ApproveOutlineRequest $request, Workspace $workspace, Article $article, Editorial $editorial): JsonResponse
    {
        Gate::authorize('operate', $article);
        $sections = $request->validated('sections');
        $outline = $editorial->approveOutline($article, is_array($sections) ? array_values($sections) : null, (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => WriterPresenter::outline($outline)], 202);
    }
}
