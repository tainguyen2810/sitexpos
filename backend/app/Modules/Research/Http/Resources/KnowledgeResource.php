<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\WebsiteKnowledge;

/**
 * @mixin WebsiteKnowledge
 */
final class KnowledgeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'value' => $this->value,
            'source' => $this->source,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence ?? [],
            'status' => $this->status,
            'edited_at' => $this->edited_at?->toIso8601ZuluString(),
        ];
    }
}
