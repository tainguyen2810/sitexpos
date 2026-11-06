<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\TopicCluster;

/**
 * Pillar/cluster group.
 *
 * @mixin TopicCluster
 */
final class ClusterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'intent' => $this->intent,
            'status' => $this->status,
            'description' => $this->description,
            'pillar_keyword' => $this->relationLoaded('pillarKeyword') && $this->pillarKeyword !== null ? ['id' => $this->pillarKeyword->id, 'keyword' => $this->pillarKeyword->keyword] : null,
            'pillar_content_item_id' => $this->pillar_content_item_id,
            'opportunity_score' => $this->opportunity_score,
            'keywords_count' => $this->keywords_count,
            'ideas_count' => $this->whenCounted('ideas'),
            'coverage' => $this->coverage ?? (object) [],
            'keywords' => KeywordResource::collection($this->whenLoaded('keywords')),
            'ideas' => IdeaResource::collection($this->whenLoaded('ideas')),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
