<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\CompetitorWebsite;

/**
 * Competitor website.
 *
 * @mixin CompetitorWebsite
 */
final class CompetitorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'status' => $this->status,
            'robots_allowed' => $this->robots_allowed,
            'pages_crawled' => $this->pages_crawled,
            'findings_count' => $this->whenCounted('findings'),
            'error' => $this->last_error_code !== null ? ['code' => $this->last_error_code, 'message' => $this->last_error_message] : null,
            'last_crawled_at' => $this->last_crawled_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
