<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Mapping between a SiteX publish request (idempotency key) and the WordPress
 * post it created. A retry with the same key updates the same post.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $idempotency_key
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string $post_type
 * @property int|null $wp_post_id
 * @property string|null $wp_status
 * @property string|null $url
 * @property string|null $payload_hash
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error_code
 * @property Carbon|null $published_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SitePublication extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'wp_post_id' => 'integer',
            'attempts' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
