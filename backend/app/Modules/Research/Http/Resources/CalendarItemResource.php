<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\ContentCalendarItem;

/**
 * Calendar slot.
 *
 * @mixin ContentCalendarItem
 */
final class CalendarItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'cluster_id' => $this->cluster_id,
            'scheduled_for' => $this->scheduled_for?->toDateString(),
            'position' => $this->position,
            'status' => $this->status,
            'source' => $this->source,
            'notes' => $this->notes,
            'idea' => $this->relationLoaded('idea') && $this->idea !== null ? [
                'id' => $this->idea->id, 'title' => $this->idea->title, 'content_type' => $this->idea->content_type,
                'search_intent' => $this->idea->search_intent, 'priority' => $this->idea->priority, 'is_pillar' => $this->idea->is_pillar,
                'status' => $this->idea->status, 'opportunity_score' => $this->idea->opportunity_score,
            ] : null,
            'approved_at' => $this->approved_at?->toIso8601ZuluString(),
        ];
    }
}
