<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Category / tag / product category / product tag synced from WordPress.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $taxonomy
 * @property int $wp_term_id
 * @property string $name
 * @property string $slug
 * @property int|null $parent_wp_id
 * @property int $count
 * @property Carbon $synced_at
 */
class SiteTaxonomy extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'wp_term_id' => 'integer',
            'parent_wp_id' => 'integer',
            'count' => 'integer',
            'synced_at' => 'datetime',
        ];
    }
}
