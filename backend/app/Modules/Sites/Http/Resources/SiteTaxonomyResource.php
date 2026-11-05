<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Sites\Domain\SiteTaxonomy;

/**
 * @mixin SiteTaxonomy
 */
final class SiteTaxonomyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'taxonomy' => $this->taxonomy,
            'wp_term_id' => $this->wp_term_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'parent_wp_id' => $this->parent_wp_id,
            'count' => $this->count,
        ];
    }
}
