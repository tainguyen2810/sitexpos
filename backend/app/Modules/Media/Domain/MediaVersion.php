<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`media_versions`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $media_asset_id
 * @property string $kind
 * @property string $mime
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property string $sha256
 * @property string $storage_path
 * @property array<string, mixed>|null $params
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class MediaVersion extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'media_versions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'params' => 'array',
        ];
    }
}
