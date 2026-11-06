<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Content index: posts, pages and WooCommerce products of the site
 * (used later for internal links and refresh suggestions).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $post_type
 * @property int $wp_post_id
 * @property string $status
 * @property string $title
 * @property string|null $url
 * @property string|null $slug
 * @property string|null $excerpt
 * @property list<int>|null $term_ids
 * @property array<string, mixed>|null $product
 * @property bool $is_managed
 * @property Carbon|null $published_at
 * @property Carbon|null $modified_at
 * @property Carbon $synced_at
 */
class SiteContentItem extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'wp_post_id' => 'integer',
            'term_ids' => 'array',
            'product' => 'array',
            'is_managed' => 'boolean',
            'published_at' => 'datetime',
            'modified_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
