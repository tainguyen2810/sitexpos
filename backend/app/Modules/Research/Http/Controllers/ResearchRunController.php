<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Modules\Research\Actions\ResearchRunSync;
use SiteXPost\Modules\Research\Actions\StartResearch;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Domain\ResearchSource;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\SourceSnapshot;
use SiteXPost\Modules\Research\Http\Requests\StartResearchRequest;
use SiteXPost\Modules\Research\Http\Resources\ResearchRunResource;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * AI Research Center: start (202 + job), progress, sources, history, cancel, retry.
 */
final class ResearchRunController
{
    public function index(Request $request, Workspace $workspace, SeoCampaign $campaign, ResearchRunSync $sync): JsonResponse
    {
        Gate::authorize('view', $campaign);
        ResearchRun::query()->where('campaign_id', $campaign->id)->whereIn('status', ResearchRun::ACTIVE)->get()->each(fn (ResearchRun $r) => $sync->sync($r));
        $query = ResearchRun::query()->where('campaign_id', $campaign->id);
        $status = $request->query('status');
        if (is_string($status) && in_array($status, ['queued', 'running', 'succeeded', 'partial', 'failed', 'canceled'], true)) {
            $query->where('status', $status);
        }

        return Paginated::response($query->orderByDesc('created_at')->orderByDesc('id'), ResearchRunResource::class, $request, 20);
    }

    public function store(StartResearchRequest $request, Workspace $workspace, SeoCampaign $campaign, StartResearch $start): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        $key = $request->header('Idempotency-Key');
        if ($key !== null && preg_match('/^[A-Za-z0-9_\-:.]{8,120}$/', $key) !== 1) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['Idempotency-Key' => ['8–120 characters: letters, digits, _ - : .']]);
        }
        [$run, $created] = $start->handle($campaign, 'manual', (string) $request->user()?->getAuthIdentifier(), $key, $request->boolean('refresh_profile'));

        return new JsonResponse(['data' => [
            'research_run' => (new ResearchRunResource($run))->resolve($request),
            'job_run' => ['id' => WorkflowRun::query()->whereKey($run->workflow_run_id)->value('job_run_id'), 'status' => 'queued', 'type' => 'workflow.research.discovery'],
        ]], $created ? 202 : 200);
    }

    public function show(Request $request, Workspace $workspace, SeoCampaign $campaign, ResearchRun $researchRun, ResearchRunSync $sync): JsonResponse
    {
        Gate::authorize('view', $campaign);
        $sync->sync($researchRun);
        $researchRun->load(['sources' => fn ($q) => $q->withCount('snapshots')->orderBy('created_at')]);
        $steps = [];
        if ($researchRun->workflow_run_id !== null) {
            $wf = WorkflowRun::query()->with('steps')->find($researchRun->workflow_run_id);
            foreach ($wf->steps ?? [] as $s) {
                $steps[] = ['key' => $s->key, 'status' => $s->status->value, 'attempts' => $s->attempts,
                    'error' => $s->error_code !== null ? ['code' => $s->error_code, 'message' => $s->error_message] : null,
                    'started_at' => $s->started_at?->toIso8601ZuluString(), 'finished_at' => $s->finished_at?->toIso8601ZuluString()];
            }
        }

        return new JsonResponse(['data' => (new ResearchRunResource($researchRun))->resolve($request) + ['steps' => $steps]]);
    }

    public function snapshots(Request $request, Workspace $workspace, SeoCampaign $campaign, ResearchRun $researchRun): JsonResponse
    {
        Gate::authorize('view', $campaign);
        $sourceIds = ResearchSource::query()->where('research_run_id', $researchRun->id)->pluck('id');
        $rows = SourceSnapshot::query()->whereIn('research_source_id', $sourceIds)->orderBy('fetched_at')->limit(300)->get()
            ->map(fn (SourceSnapshot $s) => [
                'id' => $s->id, 'source_id' => $s->research_source_id, 'url' => $s->url, 'http_status' => $s->http_status, 'title' => $s->title,
                'meta_description' => $s->meta_description, 'headings' => $s->headings ?? [], 'questions' => $s->questions ?? [],
                'internal_links_count' => count($s->internal_links ?? []), 'word_count' => $s->word_count, 'fetched_at' => $s->fetched_at?->toIso8601ZuluString(),
            ]);

        return new JsonResponse(['data' => $rows]);
    }

    public function cancel(Request $request, Workspace $workspace, SeoCampaign $campaign, ResearchRun $researchRun, ResearchRunSync $sync): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        $sync->cancel($researchRun, (string) $request->user()?->getAuthIdentifier());

        return $this->show($request, $workspace, $campaign, $researchRun->refresh(), $sync);
    }

    public function retry(Request $request, Workspace $workspace, SeoCampaign $campaign, ResearchRun $researchRun, ResearchRunSync $sync): JsonResponse
    {
        Gate::authorize('operate', $campaign);
        $sync->retry($researchRun, (string) $request->user()?->getAuthIdentifier());

        return $this->show($request, $workspace, $campaign, $researchRun->refresh(), $sync)->setStatusCode(202);
    }
}
