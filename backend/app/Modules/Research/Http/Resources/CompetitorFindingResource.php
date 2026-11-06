<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Research\Domain\CompetitorFinding;

/**
 * Competitor page/category/question (structure only; source URL and collection time).
 *
 * @mixin CompetitorFinding
 */
final class CompetitorFindingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'url' => $this->url,
            'headings' => $this->headings ?? [],
            'fetched_at' => $this->fetched_at?->toIso8601ZuluString(),
        ];
    }
}
