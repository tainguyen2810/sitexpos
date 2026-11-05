<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Domain\ContentGap;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\InternalLinkSuggestion;
use SiteXPost\Modules\Research\Domain\Keyword;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\TopicCluster;
use SiteXPost\Modules\Research\Http\Requests\ClusterRequest;
use SiteXPost\Modules\Research\Http\Requests\GapRequest;
use SiteXPost\Modules\Research\Http\Resources\ClusterResource;
use SiteXPost\Modules\Research\Http\Resources\GapResource;
use SiteXPost\Modules\Research\Http\Resources\LinkSuggestionResource;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Topic Cluster (pillar/cluster map), content gaps and internal links.
 */
final class ClusterController
{
    public function index(Request $request, Workspace $workspace, SeoCampaign $campaign): AnonymousResourceCollection
    {
        Gate::authorize('view', $campaign);
        $status = $request->query('status') === 'archived' ? 'archived' : 'active';

        return ClusterResource::collection(TopicCluster::query()->where('campaign_id', $campaign->id)->where('status', $status)
            ->with('pillarKeyword:id,keyword')->withCount('ideas')->orderByDesc('opportunity_score')->orderBy('id')->limit(200)->get());
    }

    public function show(Workspace $workspace, SeoCampaign $campaign, TopicCluster $cluster): ClusterResource
    {
        Gate::authorize('view', $campaign);

        return new ClusterResource($cluster->load([
            'pillarKeyword:id,keyword',
            'keywords' => fn ($q) => $q->with('metrics')->orderByDesc('opportunity_score')->limit(200),
            'ideas' => fn ($q) => $q->with(['primaryKeyword:id,keyword', 'calendarItem'])->orderByDesc('is_pillar')->orderByDesc('opportunity_score'),
        ])->loadCount('ideas'));
    }

    public function update(ClusterRequest $request, Workspace $workspace, SeoCampaign $campaign, TopicCluster $cluster): ClusterResource
    {
        Gate::authorize('operate', $campaign);
        $cluster->forceFill($request->validated())->save();
        Audit::record('research.cluster.updated', $cluster, ['fields' => array_keys($request->validated())]);

        return new ClusterResource($cluster->load('pillarKeyword:id,keyword')->loadCount('ideas'));
    }

    public function gaps(Request $request, Workspace $workspace, SeoCampaign $campaign): JsonResponse
    {
        Gate::authorize('view', $campaign);
        $q = ContentGap::query()->where('campaign_id', $campaign->id);
        $type = $request->query('gap_type');
        if (is_string($type) && in_array($type, ['missing_topic', 'competitor_topic', 'unanswered_question', 'cannibalization', 'overlap', 'striking_distance'], true)) {
            $q->where('gap_type', $type);
        }
        $status = $request->query('status');
        $q->where('status', is_string($status) && in_array($status, ['open', 'planned', 'dismissed'], true) ? $status : 'open');
        $cluster = $request->query('cluster_id');
        if (is_string($cluster) && preg_match('/^[0-9a-z]{26}$/', $cluster) === 1) {
            $q->where('cluster_id', $cluster);
        }

        return Paginated::response($q->orderByDesc('score')->orderByDesc('id'), GapResource::class, $request, 50);
    }

    public function updateGap(GapRequest $request, Workspace $workspace, SeoCampaign $campaign, ContentGap $gap): GapResource
    {
        Gate::authorize('operate', $campaign);
        $gap->forceFill(['status' => $request->string('status')->toString()])->save();
        Audit::record('research.gap.updated', $gap, ['status' => $gap->status]);

        return new GapResource($gap);
    }

    public function links(Request $request, Workspace $workspace, SeoCampaign $campaign): AnonymousResourceCollection
    {
        Gate::authorize('view', $campaign);
        $q = InternalLinkSuggestion::query()->where('campaign_id', $campaign->id);
        $cluster = $request->query('cluster_id');
        if (is_string($cluster) && preg_match('/^[0-9a-z]{26}$/', $cluster) === 1) {
            $q->where('cluster_id', $cluster);
        }

        $rows = $q->orderByDesc('score')->orderBy('id')->limit(500)->get();
        $labels = self::linkLabels($campaign, $rows);
        foreach ($rows as $row) {
            $row->setAttribute('from_label', $labels[$row->from_type.':'.$row->from_id] ?? null);
            $row->setAttribute('to_label', $labels[$row->to_type.':'.$row->to_id] ?? null);
        }

        return LinkSuggestionResource::collection($rows);
    }

    /**
     * Human-readable names for link endpoints (idea titles, cluster names,
     * titles of existing posts recorded as coverage evidence).
     *
     * @param  Collection<int, InternalLinkSuggestion>  $rows
     * @return array<string, string>
     */
    private static function linkLabels(SeoCampaign $campaign, Collection $rows): array
    {
        $ids = ['idea' => [], 'cluster' => [], 'content' => []];
        foreach ($rows as $r) {
            $ids[$r->from_type][] = $r->from_id;
            $ids[$r->to_type][] = $r->to_id;
        }
        $labels = [];
        foreach (ContentIdea::query()->where('campaign_id', $campaign->id)->whereIn('id', array_unique($ids['idea'] ?? []))->pluck('title', 'id') as $id => $title) {
            $labels['idea:'.$id] = (string) $title;
        }
        foreach (TopicCluster::query()->where('campaign_id', $campaign->id)->whereIn('id', array_unique($ids['cluster'] ?? []))->pluck('name', 'id') as $id => $name) {
            $labels['cluster:'.$id] = (string) $name;
        }
        $want = array_flip(array_map('strval', $ids['content'] ?? []));
        if ($want !== []) {
            Keyword::query()->where('campaign_id', $campaign->id)->whereNotNull('covered_by')->select(['id', 'covered_by'])
                ->chunkById(500, function ($kws) use (&$labels, $want) {
                    foreach ($kws as $kw) {
                        foreach ($kw->covered_by ?? [] as $c) {
                            $cid = (string) ($c['id'] ?? '');
                            if (isset($want[$cid]) && ! isset($labels['content:'.$cid])) {
                                $labels['content:'.$cid] = (string) ($c['title'] ?? '');
                            }
                        }
                    }
                });
        }

        return $labels;
    }
}
