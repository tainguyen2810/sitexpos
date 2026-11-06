<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\ResearchSource;

/**
 * @mixin ResearchSource
 */
final class ResearchSourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label,
            'url' => $this->url,
            'status' => $this->status,
            'http_status' => $this->http_status,
            'robots_allowed' => $this->robots_allowed,
            'items_count' => $this->items_count,
            'error' => $this->error_code !== null ? ['code' => $this->error_code, 'message' => $this->error_message] : null,
            'snapshots_count' => $this->whenCounted('snapshots'),
            'fetched_at' => $this->fetched_at?->toIso8601ZuluString(),
        ];
    }
}
