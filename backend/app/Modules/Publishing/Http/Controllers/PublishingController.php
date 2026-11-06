<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Modules\Publishing\Actions\ManageJobs;
use SiteXPost\Modules\Publishing\Actions\ResolveConflict;
use SiteXPost\Modules\Publishing\Actions\UpdatePolicy;
use SiteXPost\Modules\Publishing\Domain\PublishingConflict;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\PublishingSchedule;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Http\PublishingPresenter as P;
use SiteXPost\Modules\Publishing\Http\Requests\ApproveJobRequest;
use SiteXPost\Modules\Publishing\Http\Requests\CalendarRequest;
use SiteXPost\Modules\Publishing\Http\Requests\CreateJobRequest;
use SiteXPost\Modules\Publishing\Http\Requests\PolicyRequest;
use SiteXPost\Modules\Publishing\Http\Requests\RescheduleJobRequest;
use SiteXPost\Modules\Publishing\Http\Requests\ResolveConflictRequest;
use SiteXPost\Modules\Publishing\Jobs\CheckMappingSync;
use SiteXPost\Modules\Publishing\Support\PublishingLimits;
use SiteXPost\Modules\Publishing\Support\PublishingStatus as S;
use SiteXPost\Modules\Publishing\Support\SitePolicy;
use SiteXPost\Modules\Research\Contracts\PlannerDirectory;
use SiteXPost\Modules\Seo\Contracts\SeoGate;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * Publishing Center, Calendar, History, Conflicts and Website Publishing
 * Settings. Thin: validate → authorize → action → present.
 */
final class PublishingController
{
    public function __construct(private readonly ArticleCatalog $articles, private readonly SiteDirectory $sites) {}

    public function articles(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', PublishingJob::class);
        $siteId = $request->query('site_id');
        $list = $this->articles->list(is_string($siteId) && $siteId !== '' ? $siteId : null, ['APPROVED'], 200);
        $ids = array_column($list, 'id');
        $active = PublishingJob::query()->whereIn('article_id', $ids)->whereNotNull('active_key')->get()->keyBy('article_id');
        $mapped = WordPressPostMapping::query()->whereIn('article_id', $ids)->where('is_current', true)->get()->keyBy('article_id');

        return new JsonResponse(['data' => array_map(fn ($a) => [
            'id' => $a['id'], 'title' => $a['title'], 'site_id' => $a['site_id'], 'campaign_id' => $a['campaign_id'], 'primary_keyword' => $a['primary_keyword'],
            'approved_at' => $a['approved_at'], 'unresolved_critical' => $a['unresolved_critical'], 'unresolved_high' => $a['unresolved_high'], 'is_demo' => $a['is_demo'],
            'active_job' => ($j = $active->get($a['id'])) !== null ? ['id' => $j->id, 'status' => $j->status, 'mode' => $j->mode] : null,
            'wordpress' => ($m = $mapped->get($a['id'])) !== null && $m->wp_post_id !== null ? ['wp_post_id' => $m->wp_post_id, 'url' => $m->url, 'status' => $m->wp_status] : null,
        ], $list)]);
    }

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', PublishingJob::class);
        $q = PublishingJob::query();
        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $q->whereIn('status', array_values(array_intersect(explode(',', $status), S::ALL)));
        }
        foreach (['site_id', 'article_id', 'publishing_batch_id'] as $f) {
            $v = $request->query($f);
            if (is_string($v) && preg_match('/^[0-9a-z]{26}$/i', $v) === 1) {
                $q->where($f, $v);
            }
        }
        $titles = $this->titles();

        return P::page($q->orderByDesc('updated_at')->orderByDesc('id'), fn (PublishingJob $j) => P::job($j, $titles), $request);
    }

    public function store(CreateJobRequest $request, Workspace $workspace, ManageJobs $jobs): JsonResponse
    {
        Gate::authorize('create', PublishingJob::class);
        $data = $request->validated();
        $key = $request->header('Idempotency-Key');
        if ($key !== null && (! is_string($key) || preg_match('/^[A-Za-z0-9_\-:.]{8,120}$/', $key) !== 1)) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['Idempotency-Key' => ['8–120 characters: letters, digits, _ - : .']]);
        }
        $userId = (string) $request->user()?->getAuthIdentifier();
        $job = $jobs->create(['article_id' => (string) $data['article_id'], 'mode' => (string) $data['mode'], 'scheduled_at' => $data['scheduled_at'] ?? null], $userId, $key);
        if (($data['approve'] ?? false) === true && $job->status === S::READY_FOR_REVIEW) {
            Gate::authorize('publish', $job);
            $job = $jobs->approve($job, $userId);
        }

        return new JsonResponse(['data' => P::job($job->refresh(), $this->titles(), true)], $job->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Workspace $workspace, PublishingJob $publishingJob): JsonResponse
    {
        Gate::authorize('view', $publishingJob);

        return new JsonResponse(['data' => P::job($publishingJob, $this->titles(), true)]);
    }

    public function recheck(Workspace $workspace, PublishingJob $publishingJob, ManageJobs $jobs): JsonResponse
    {
        Gate::authorize('operate', $publishingJob);
        if (! in_array($publishingJob->status, [S::DRAFT, S::READY_FOR_REVIEW], true)) {
            throw ApiException::conflict('invalid_transition', __('publishing.errors.invalid_transition', ['from' => $publishingJob->status, 'to' => S::READY_FOR_REVIEW]));
        }

        return new JsonResponse(['data' => P::job($jobs->recheck($publishingJob), $this->titles(), true)]);
    }

    public function approve(ApproveJobRequest $request, Workspace $workspace, PublishingJob $publishingJob, ManageJobs $jobs): JsonResponse
    {
        Gate::authorize('publish', $publishingJob);
        $job = $jobs->approve($publishingJob, (string) $request->user()?->getAuthIdentifier(), $request->validated('scheduled_at'));

        return new JsonResponse(['data' => P::job($job->refresh(), $this->titles(), true)]);
    }

    public function reschedule(RescheduleJobRequest $request, Workspace $workspace, PublishingJob $publishingJob, ManageJobs $jobs): JsonResponse
    {
        Gate::authorize('publish', $publishingJob);
        $job = $jobs->reschedule($publishingJob, (string) $request->validated('scheduled_at'), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => P::job($job->refresh(), $this->titles(), true)]);
    }

    public function cancel(Request $request, Workspace $workspace, PublishingJob $publishingJob, ManageJobs $jobs): JsonResponse
    {
        Gate::authorize('operate', $publishingJob);
        $job = $jobs->cancel($publishingJob, (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => P::job($job->refresh(), $this->titles(), true)]);
    }

    public function retry(Request $request, Workspace $workspace, PublishingJob $publishingJob, ManageJobs $jobs): JsonResponse
    {
        Gate::authorize('publish', $publishingJob);
        $job = $jobs->retry($publishingJob, (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => P::job($job->refresh(), $this->titles(), true)], 202);
    }

    public function html(Workspace $workspace, PublishingJob $publishingJob): JsonResponse
    {
        Gate::authorize('view', $publishingJob);

        return new JsonResponse(['data' => ['html' => (string) $publishingJob->rendered_html, 'note' => __('publishing.labels.sent_html')]]);
    }

    public function preview(Workspace $workspace, string $articleRef, SeoGate $seo): JsonResponse
    {
        Gate::authorize('viewAny', PublishingJob::class);
        $a = $this->article($articleRef);
        $render = $seo->render($a['id'], false);
        $meta = $seo->metadata($a['id']);
        $profile = $this->sites->publishingProfile($a['site_id']);
        $mapping = WordPressPostMapping::query()->where('article_id', $a['id'])->where('is_current', true)->first();
        $url = $mapping->url ?? (($profile !== null ? rtrim((string) $profile['url'], '/') : '').'/'.($meta['slug'] ?? '').'/');

        return new JsonResponse(['data' => [
            'simulation' => true, 'disclaimer' => __('publishing.labels.preview_disclaimer'), 'approved' => $render['approved'],
            'title' => $render['title'], 'html' => $render['html'], 'featured' => $render['featured'], 'images' => $render['images'],
            'links' => $render['links'], 'stale_links' => $render['stale_links'],
            'snippet' => ['title' => (string) ($meta['seo_title'] ?: $render['title']), 'url' => $url, 'description' => (string) ($meta['meta_description'] ?? '')],
            'seo_adapter' => $profile['seo_adapter'] ?? null,
        ]]);
    }

    public function calendar(CalendarRequest $request, Workspace $workspace, PlannerDirectory $planner): JsonResponse
    {
        Gate::authorize('viewAny', PublishingJob::class);
        $data = $request->validated();
        $from = (string) $data['from'];
        $to = (string) $data['to'];
        if ((strtotime($to) - strtotime($from)) > 92 * 86400) {
            throw new ApiException(422, 'range_too_large', __('publishing.codes.range_too_large'));
        }
        $siteId = $data['site_id'] ?? null;
        $q = PublishingJob::query()->whereIn('status', [S::SCHEDULED, S::PUBLISHING, S::PUBLISHED, S::FAILED])
            ->where(fn ($w) => $w->whereBetween('scheduled_at', [$from.' 00:00:00', $to.' 23:59:59'])->orWhereBetween('published_at', [$from.' 00:00:00', $to.' 23:59:59']));
        if ($siteId !== null) {
            $q->where('site_id', $siteId);
        }
        $titles = $this->titles();
        $schedules = PublishingSchedule::query()->whereIn('publishing_job_id', (clone $q)->select('id'))->get()->keyBy('publishing_job_id');

        return new JsonResponse(['data' => [
            'jobs' => $q->orderBy('scheduled_at')->limit(500)->get()->map(fn (PublishingJob $j) => P::job($j, $titles) + [
                'local_time' => $schedules->get($j->id)?->local_time, 'schedule_status' => $schedules->get($j->id)?->status])->all(),
            'planned' => $planner->calendar($siteId, $from, $to),
        ]]);
    }

    public function conflicts(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', PublishingJob::class);
        $q = PublishingConflict::query();
        $status = $request->query('status');
        if (in_array($status, ['open', 'resolved'], true)) {
            $q->where('status', $status);
        }

        return P::page($q->orderByDesc('created_at')->orderByDesc('id'), fn (PublishingConflict $c) => P::conflict($c) + ['article_title' => $this->titles()[$c->article_id] ?? null], $request);
    }

    public function conflict(Workspace $workspace, PublishingConflict $conflict): JsonResponse
    {
        Gate::authorize('view', $conflict);

        return new JsonResponse(['data' => P::conflict($conflict) + ['article_title' => $this->titles()[$conflict->article_id] ?? null]]);
    }

    public function resolve(ResolveConflictRequest $request, Workspace $workspace, PublishingConflict $conflict, ResolveConflict $action): JsonResponse
    {
        Gate::authorize('publish', $conflict);
        $c = $action->handle($conflict, (string) $request->validated('resolution'), $request->validated('note'), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => P::conflict($c->refresh())]);
    }

    public function mappings(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', PublishingJob::class);
        $q = WordPressPostMapping::query();
        $site = $request->query('site_id');
        if (is_string($site) && preg_match('/^[0-9a-z]{26}$/i', $site) === 1) {
            $q->where('site_id', $site);
        }

        return P::page($q->orderByDesc('updated_at')->orderByDesc('id'), fn (WordPressPostMapping $m) => P::mapping($m) + ['article_title' => $this->titles()[$m->article_id] ?? null], $request);
    }

    public function checkMapping(Request $request, Workspace $workspace, WordPressPostMapping $mapping): JsonResponse
    {
        Gate::authorize('operate', $mapping);
        $run = new JobRun;
        $run->forceFill(['type' => 'publishing.sync_check', 'subject_type' => 'wordpress_post_mapping', 'subject_id' => $mapping->id,
            'created_by' => (string) $request->user()?->getAuthIdentifier()])->save();
        CheckMappingSync::dispatch($mapping->id, $run->id);

        return new JsonResponse(['job_run' => ['id' => $run->id, 'status' => $run->refresh()->status]], 202);
    }

    public function policy(Workspace $workspace, string $site): JsonResponse
    {
        Gate::authorize('viewAny', PublishingJob::class);
        $profile = $this->site($site);

        return new JsonResponse(['data' => SitePolicy::for($profile['id']) + ['site' => $this->siteInfo($profile), 'platform' => PublishingLimits::for($workspace->id)]]);
    }

    public function updatePolicy(PolicyRequest $request, Workspace $workspace, string $site, UpdatePolicy $action): JsonResponse
    {
        Gate::authorize('configure', PublishingJob::class);
        $profile = $this->site($site);
        $policy = $action->handle($profile['id'], $workspace->id, $request->validated(), (string) $request->user()?->getAuthIdentifier());

        return new JsonResponse(['data' => $policy + ['site' => $this->siteInfo($profile), 'platform' => PublishingLimits::for($workspace->id)]]);
    }

    /**
     * @return array<string, string>
     */
    private function titles(): array
    {
        return array_column($this->articles->list(null, ['IDEA_SELECTED', 'RESEARCHING', 'RESEARCH_COMPLETE', 'BRIEF_READY', 'OUTLINING', 'WRITING', 'FACT_CHECKING', 'REVISING', 'QUALITY_REVIEW', 'NEEDS_HUMAN_REVIEW', 'APPROVED', 'FAILED', 'CANCELLED'], 1000), 'title', 'id');
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function siteInfo(array $p): array
    {
        return array_intersect_key($p, array_flip(['id', 'url', 'host', 'status', 'paired', 'timezone', 'seo_adapter', 'yoast', 'rank_math', 'can_publish', 'can_upload', 'plugin_version', 'wp_version', 'last_error_code']));
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
