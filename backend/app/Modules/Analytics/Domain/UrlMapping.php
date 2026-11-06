<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`analytics_url_mappings`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $page_hash
 * @property string $page_url
 * @property ?string $article_id
 * @property ?int $wp_post_id
 * @property string $source
 * @property bool $is_current
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class UrlMapping extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'analytics_url_mappings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'wp_post_id' => 'integer',
            'is_current' => 'boolean',
        ];
    }
}
