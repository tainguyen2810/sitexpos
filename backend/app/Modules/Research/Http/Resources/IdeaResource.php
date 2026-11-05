<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\ContentIdea;

/**
 * A content idea with its keywords, evidence and score breakdown.
 *
 * @mixin ContentIdea
 */
final class IdeaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'search_intent' => $this->search_intent,
            'content_type' => $this->content_type,
            'audience' => $this->audience,
            'angle' => $this->angle,
            'priority' => $this->priority,
            'is_pillar' => $this->is_pillar,
            'status' => $this->status,
            'source' => $this->source,
            'cluster_id' => $this->cluster_id,
            'cluster_name' => $this->relationLoaded('cluster') ? $this->cluster?->name : null,
            'research_run_id' => $this->research_run_id,
            'primary_keyword' => $this->relationLoaded('primaryKeyword') && $this->primaryKeyword !== null ? ['id' => $this->primaryKeyword->id, 'keyword' => $this->primaryKeyword->keyword] : null,
            'secondary_keywords' => $this->relationLoaded('keywords')
                ? $this->keywords->filter(fn ($k) => $k->pivot->role === 'secondary')->map(fn ($k) => ['id' => $k->id, 'keyword' => $k->keyword])->values()
                : [],
            'rationale' => $this->rationale,
            'evidence' => $this->evidence ?? (object) [],
            'opportunity' => [
                'score' => $this->opportunity_score,
                'kind' => 'internal_estimate',
                'components' => ($this->score_breakdown ?? [])['components'] ?? [],
                'measured' => (bool) (($this->score_breakdown ?? [])['measured'] ?? false),
            ],
            'overlap' => $this->overlap_content_item_id !== null ? ['content_item_id' => $this->overlap_content_item_id, 'score' => $this->overlap_score] : null,
            'brief' => $this->brief,
            'calendar' => $this->relationLoaded('calendarItem') && $this->calendarItem !== null
                ? ['id' => $this->calendarItem->id, 'scheduled_for' => $this->calendarItem->scheduled_for?->toDateString(), 'status' => $this->calendarItem->status] : null,
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
