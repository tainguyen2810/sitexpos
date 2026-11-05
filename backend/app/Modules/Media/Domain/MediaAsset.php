<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`media_assets`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class MediaAsset extends Model
{
    use BelongsToWorkspace, HasUlids, SoftDeletes;

    protected $table = 'media_assets';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'is_ai_generated' => 'boolean',
            'metadata' => 'array',
            'wp_media_id' => 'integer',
            'usage_count' => 'integer',
            'is_demo' => 'boolean',
        ];
    }
}
