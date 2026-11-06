<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Media uploaded to WordPress, deduplicated by idempotency key.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $idempotency_key
 * @property string $sha256
 * @property string $mime
 * @property int $bytes
 * @property int $wp_media_id
 * @property string $url
 */
class SiteMedia extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'wp_media_id' => 'integer',
        ];
    }
}
