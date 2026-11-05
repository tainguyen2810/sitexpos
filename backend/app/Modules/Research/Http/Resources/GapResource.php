<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\ContentGap;

/**
 * Content gap / cannibalization finding.
 *
 * @mixin ContentGap
 */
final class GapResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gap_type' => $this->gap_type,
            'topic' => $this->topic,
            'description' => $this->description,
            'evidence' => $this->evidence ?? (object) [],
            'cluster_id' => $this->cluster_id,
            'competitor_website_id' => $this->competitor_website_id,
            'related_content_item_ids' => $this->related_content_item_ids ?? [],
            'score' => $this->score,
            'status' => $this->status,
            'research_run_id' => $this->research_run_id,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
