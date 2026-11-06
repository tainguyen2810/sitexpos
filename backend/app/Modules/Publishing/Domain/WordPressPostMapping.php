<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`wordpress_post_mappings`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $article_id
 * @property string $external_content_id
 * @property string $idempotency_key
 * @property bool $is_current
 * @property ?int $wp_post_id
 * @property ?string $wp_status
 * @property ?string $url
 * @property ?string $slug
 * @property string $site_host
 * @property ?string $last_published_version_id
 * @property ?string $last_payload_hash
 * @property ?string $last_synced_hash
 * @property ?string $last_seo_hash
 * @property ?string $last_modified_gmt
 * @property ?int $last_revision_id
 * @property ?Carbon $last_published_at
 * @property ?Carbon $last_checked_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class WordPressPostMapping extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'wordpress_post_mappings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'wp_post_id' => 'integer',
            'last_revision_id' => 'integer',
            'last_published_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }
}
