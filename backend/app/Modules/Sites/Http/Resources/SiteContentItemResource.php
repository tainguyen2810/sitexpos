<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SiteXPost\Modules\Sites\Domain\SiteContentItem;

/**
 * @mixin SiteContentItem
 */
final class SiteContentItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'post_type' => $this->post_type,
            'wp_post_id' => $this->wp_post_id,
            'status' => $this->status,
            'title' => $this->title,
            'url' => $this->url,
            'excerpt' => $this->excerpt,
            'product' => $this->product,
            'term_ids' => $this->term_ids ?? [],
            'author_wp_id' => $this->author_wp_id,
            'author_name' => $this->author_name,
            'is_managed' => $this->is_managed,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            'modified_at' => $this->modified_at?->toIso8601ZuluString(),
        ];
    }
}
