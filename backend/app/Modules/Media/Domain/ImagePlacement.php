<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`image_placements`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class ImagePlacement extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'image_placements';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
