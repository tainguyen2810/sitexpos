<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\InternalLinkSuggestion;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Http\Requests\UpdateIdeaRequest;
use SiteXPost\Modules\Research\Http\Resources\IdeaResource;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Topic Discovery: list, edit, approve/reject, convert to a Content Brief.
 */
final class IdeaController
{
    public function index(Request $request, Workspace $workspace, SeoCampaign $campaign): JsonResponse
    {
        Gate::authorize('view', $campaign);
        $q = ContentIdea::query()->where('campaign_id', $campaign->id)->with(['primaryKeyword:id,keyword', 'keywords:id,keyword', 'cluster:id,name', 'calendarItem']);

        foreach (['status' => ['suggested', 'approved', 'rejected', 'briefed'], 'search_intent' => ['informational', 'commercial', 'transactional', 'navigational'],
            'content_type' => ['informational', 'commercial', 'transactional', 'navigational', 'tutorial', 'comparison', 'troubleshooting', 'faq', 'pillar'],
            'priority' => ['high', 'medium', 'low'], 'source' => ['ai', 'rule', 'user', 'demo']] as $field => $allowed) {
            $v = $request->query($field);
            if (is_string($v) && in_array($v, $allowed, true)) {
                $q->where($field, $v);
            }
        }
        $cluster = $request->query('cluster_id');
        if (is_string($cluster) && preg_match('/^[0-9a-z]{26}$/', $cluster) === 1) {
            $q->where('cluster_id', $cluster);
        }
        $run = $request->query('research_run_id');
        if (is_string($run) && preg_match('/^[0-9a-z]{26}$/', $run) === 1) {
            $q->where('research_run_id', $run);
        }
        if ($request->query('unscheduled') === '1') {
            $q->whereDoesntHave('calendarItem');
        }
        $search = $request->query('q');
        if (is_string($search) && trim($search) !== '') {
            $q->where('title', 'like', '%'.addcslashes(mb_substr(trim($search), 0, 100), '%_\\').'%');
        }
        $dir = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        match ($request->query('sort')) {
            'title' => $q->orderBy('title', $dir)->orderBy('id', $dir),
            'created' => $q->orderBy('created_at', $dir)->orderBy('id', $dir),
            default => $q->orderBy('opportunity_score', $dir)->orderBy('id', $dir),
        };

        return Paginated::response($q, IdeaResource::class, $request, 25);
    }

    public function show(Workspace $workspace, SeoCampaign $campaign, ContentIdea $idea): IdeaResource
    {
        Gate::authorize('view', $campaign);

        return new IdeaResource($idea->load(['primaryKeyword:id,keyword', 'keywords:id,keyword', 'cluster:id,name', 'calendarItem']));
    }

    public function update(UpdateIdeaRequest $request, Workspace $workspace, SeoCampaign $campaign, ContentIdea $idea): IdeaResource
    {
        Gate::authorize('operate', $campaign);
        $data = $request->validated();
        if (isset($data['status'])) {
            if ($idea->status === 'briefed' && $data['status'] === 'suggested') {
                unset($data['status']);
            } else {
                $data['decided_by'] = (string) $request->user()?->getAuthIdentifier();
                $data['decided_at'] = now();
            }
        }
        $idea->forceFill($data)->save();
        if (($data['status'] ?? null) === 'rejected') {
            $idea->calendarItem?->forceFill(['status' => 'canceled'])->save();
        }
        Audit::record('research.idea.updated', $idea, ['fields' => array_keys($request->validated()), 'status' => $idea->status]);

        return $this->show($workspace, $campaign, $idea);
    }

    /**
     * Content Brief — the hand-off document Phase 5 (writing) will consume.
     * Built only from stored research data; no AI call, no article created.
     */
    public function brief(Request $request, Workspace $workspace, SeoCampaign $campaign, ContentIdea $idea): IdeaResource
    {
        Gate::authorize('operate', $campaign);
        if ($idea->status === 'rejected') {
            throw ApiException::conflict('invalid_state_transition', __('research.errors.idea_rejected'));
        }
        $idea->load(['primaryKeyword', 'keywords', 'cluster']);
        $links = InternalLinkSuggestion::query()->where('campaign_id', $campaign->id)
            ->where(fn ($q) => $q->where(['from_type' => 'idea', 'from_id' => $idea->id])->orWhere(fn ($w) => $w->where(['to_type' => 'idea', 'to_id' => $idea->id])))
            ->limit(20)->get()->map(fn ($l) => ['from' => [$l->from_type, $l->from_id], 'to' => [$l->to_type, $l->to_id], 'anchor' => $l->anchor_text, 'reason' => $l->reason])->all();
        $competitorRefs = [];
        $primary = $idea->primaryKeyword;
        if ($primary !== null) {
            foreach ($primary->evidence ?? [] as $ev) {
                if (str_starts_with((string) ($ev['type'] ?? ''), 'competitor')) {
                    $competitorRefs[] = ['title' => $ev['title'] ?? null, 'url' => $ev['url'] ?? null, 'fetched_at' => $ev['fetched_at'] ?? null];
                }
            }
        }

        $brief = [
            'version' => 1,
            'title' => $idea->title,
            'primary_keyword' => $primary?->keyword,
            'secondary_keywords' => $idea->keywords->filter(fn ($k) => $k->pivot->role === 'secondary')->pluck('keyword')->values()->all(),
            'search_intent' => $idea->search_intent,
            'content_type' => $idea->content_type,
            'audience' => $idea->audience,
            'angle' => $idea->angle,
            'outline' => ($idea->evidence ?? [])['outline'] ?? [],
            'cluster' => $idea->cluster?->name,
            'is_pillar' => $idea->is_pillar,
            'internal_links' => $links,
            'avoid_duplicating' => ($idea->evidence ?? [])['related_existing'] ?? [],
            'competitor_references' => array_slice($competitorRefs, 0, 5),
            'rules' => [
                'Không sao chép nội dung đối thủ; chỉ dùng làm tham khảo cấu trúc.',
                'Không bịa số liệu, thứ hạng hay trích dẫn; dữ liệu phải có nguồn.',
                'Bài viết phải qua kiểm chứng trước khi xuất bản.',
            ],
            'created_at' => now()->toIso8601ZuluString(),
            'created_by' => (string) $request->user()?->getAuthIdentifier(),
        ];
        $idea->forceFill(['brief' => $brief, 'briefed_at' => now(), 'status' => 'briefed', 'decided_by' => $brief['created_by'], 'decided_at' => now()])->save();
        Audit::record('research.idea.briefed', $idea);

        return $this->show($workspace, $campaign, $idea);
    }
}
