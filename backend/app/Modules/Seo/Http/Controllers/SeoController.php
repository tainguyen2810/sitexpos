<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Seo\Actions\InternalLinks;
use SiteXPost\Modules\Seo\Actions\ManageMetadata;
use SiteXPost\Modules\Seo\Actions\RunSeoAudit;
use SiteXPost\Modules\Seo\Actions\UpdateSchemaConfig;
use SiteXPost\Modules\Seo\Contracts\SeoGate;
use SiteXPost\Modules\Seo\Domain\SeoAuditRun;
use SiteXPost\Modules\Seo\Domain\SeoLinkSuggestion;
use SiteXPost\Modules\Seo\Domain\SeoMetadata;
use SiteXPost\Modules\Seo\Http\Requests\DecideLinkRequest;
use SiteXPost\Modules\Seo\Http\Requests\SchemaConfigRequest;
use SiteXPost\Modules\Seo\Http\Requests\UpdateMetadataRequest;
use SiteXPost\Modules\Seo\Http\SeoPresenter;
use SiteXPost\Modules\Seo\Jobs\CheckArticleLinks;
use SiteXPost\Modules\Seo\Support\SiteSeoConfig;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * SEO Audit, SEO Metadata Editor, Internal Link Suggestions and Website SEO
 * settings. `{article}` / `{site}` are resolved through the owning module's
 * contract inside the tenant scope (another tenant's id → 404).
 */
final class SeoController
{
    public function __construct(private readonly ArticleCatalog $articles, private readonly SiteDirectory $sites) {}

    public function articles(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', SeoMetadata::class);
        $siteId = $request->query('site_id');
        $list = $this->articles->list(is_string($siteId) && $siteId !== '' ? $siteId : null, ['APPROVED', 'NEEDS_HUMAN_REVIEW', 'QUALITY_REVIEW'], 200);
        $ids = array_column($list, 'id');
        $latest = SeoAuditRun::query()->whereIn('article_id', $ids)->orderByDesc('created_at')->get()->unique('article_id')->keyBy('article_id');

        return new JsonResponse(['data' => array_map(fn ($a) => [
            'id' => $a['id'], 'title' => $a['title'], 'status' => $a['status'], 'site_id' => $a['site_id'], 'primary_keyword' => $a['primary_keyword'],
            'latest_audit' => ($r = $latest->get($a['id'])) !== null ? SeoPresenter::run($r) : null,
        ], $list)]);
    }

    public function metadata(Workspace $workspace, string $articleRef, ManageMetadata $action): JsonResponse
    {
        Gate::authorize('viewAny', SeoMetadata::class);
        $a = $this->article($articleRef);

        return new JsonResponse(['data' => SeoPresenter::metadata($action->get($a['id'])) + ['site_policy' => SiteSeoConfig::for($a['site_id'])]]);
    }

    public function generateMetadata(Request $request, Workspace $workspace, string $articleRef, ManageMetadata $action): JsonResponse
    {
        Gate::authorize('create', SeoMetadata::class);
        $a = $this->article($articleRef);

        return new JsonResponse(['data' => SeoPresenter::metadata($action->generate($a['id'], (string) $request->user()?->getAuthIdentifier()))]);
    }

    public function updateMetadata(UpdateMetadataRequest $request, Workspace $workspace, string $articleRef, ManageMetadata $action): JsonResponse
    {
        Gate::authorize('create', SeoMetadata::class);
        $a = $this->article($articleRef);
        $data = $request->validated();
        $lock = (int) $data['lock_version'];
        unset($data['lock_version']);

        return new JsonResponse(['data' => SeoPresenter::metadata($action->update($a['id'], $data, $lock, (string) $request->user()?->getAuthIdentifier()))]);
    }

    public function audits(Request $request, Workspace $workspace, string $articleRef): JsonResponse
    {
        Gate::authorize('viewAny', SeoMetadata::class);
        $a = $this->article($articleRef);
        $runs = SeoAuditRun::query()->where('article_id', $a['id'])->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();

        return new JsonResponse(['data' => $runs->map(fn (SeoAuditRun $r) => SeoPresenter::run($r))->all()]);
    }

    public function runAudit(Request $request, Workspace $workspace, string $articleRef, RunSeoAudit $action): JsonResponse
    {
        Gate::authorize('create', SeoMetadata::class);
        $a = $this->article($articleRef);
        $run = $action->run($a['id'], (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => SeoPresenter::run($run, true)], 201);
    }

    public function checkLinks(Request $request, Workspace $workspace, string $articleRef): JsonResponse
    {
        Gate::authorize('create', SeoMetadata::class);
        $a = $this->article($articleRef);
        $run = new JobRun;
        $run->forceFill(['type' => 'seo.link_check', 'subject_type' => 'article', 'subject_id' => $a['id'], 'created_by' => (string) $request->user()?->getAuthIdentifier()])->save();
        CheckArticleLinks::dispatch($a['id'], (string) $request->user()?->getAuthIdentifier(), $run->id);

        return new JsonResponse(['job_run' => ['id' => $run->id, 'status' => $run->refresh()->status]], 202);
    }

    public function audit(Workspace $workspace, SeoAuditRun $auditRun): JsonResponse
    {
        Gate::authorize('view', $auditRun);

        return new JsonResponse(['data' => SeoPresenter::run($auditRun, true)]);
    }

    public function schema(Workspace $workspace, string $articleRef, SeoGate $gate): JsonResponse
    {
        Gate::authorize('viewAny', SeoMetadata::class);
        $a = $this->article($articleRef);
        $own = $this->sites->publication($a['site_id'], 'article', $a['id']);

        return new JsonResponse(['data' => $gate->schema($a['id'], ['url' => $own['url'] ?? null]) + ['disclaimer' => __('seo.labels.schema_disclaimer')]]);
    }

    public function links(Workspace $workspace, string $articleRef): JsonResponse
    {
        Gate::authorize('viewAny', SeoMetadata::class);
        $a = $this->article($articleRef);
        $rows = SeoLinkSuggestion::query()->where('article_id', $a['id'])->orderByDesc('relevance')->orderBy('id')->limit(100)->get();

        return new JsonResponse(['data' => $rows->map(fn (SeoLinkSuggestion $s) => SeoPresenter::link($s))->all()]);
    }

    public function suggestLinks(Request $request, Workspace $workspace, string $articleRef, InternalLinks $action): JsonResponse
    {
        Gate::authorize('create', SeoMetadata::class);
        $a = $this->article($articleRef);
        $n = $action->suggest($a['id'], (string) $request->user()?->getAuthIdentifier());
        $rows = SeoLinkSuggestion::query()->where('article_id', $a['id'])->orderByDesc('relevance')->orderBy('id')->limit(100)->get();

        return new JsonResponse(['data' => $rows->map(fn (SeoLinkSuggestion $s) => SeoPresenter::link($s))->all(), 'suggested' => $n]);
    }

    public function decideLink(DecideLinkRequest $request, Workspace $workspace, SeoLinkSuggestion $suggestion, InternalLinks $action): JsonResponse
    {
        Gate::authorize('operate', $suggestion);

        return new JsonResponse(['data' => SeoPresenter::link($action->decide($suggestion, (string) $request->validated('status'), (string) $request->user()?->getAuthIdentifier()))]);
    }

    public function siteConfig(Workspace $workspace, string $site): JsonResponse
    {
        Gate::authorize('viewAny', SeoMetadata::class);
        $profile = $this->site($site);

        return new JsonResponse(['data' => SiteSeoConfig::for($profile['id']) + ['site_id' => $profile['id'], 'seo_adapter' => $profile['seo_adapter'],
            'yoast' => $profile['yoast'], 'rank_math' => $profile['rank_math']]]);
    }

    public function updateSiteConfig(SchemaConfigRequest $request, Workspace $workspace, string $site, UpdateSchemaConfig $action): JsonResponse
    {
        Gate::authorize('configure', SeoMetadata::class);
        $profile = $this->site($site);
        $action->handle($profile['id'], $request->validated(), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => SiteSeoConfig::for($profile['id']) + ['site_id' => $profile['id'], 'seo_adapter' => $profile['seo_adapter'],
            'yoast' => $profile['yoast'], 'rank_math' => $profile['rank_math']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function article(string $id): array
    {
        return preg_match('/^[0-9a-z]{26}$/i', $id) === 1 ? ($this->articles->find($id) ?? throw ApiException::notFound()) : throw ApiException::notFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function site(string $id): array
    {
        return preg_match('/^[0-9a-z]{26}$/i', $id) === 1 ? ($this->sites->publishingProfile($id) ?? throw ApiException::notFound()) : throw ApiException::notFound();
    }
}
