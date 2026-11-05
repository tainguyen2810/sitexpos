<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`image_placements`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property ?string $media_asset_id
 * @property string $slot
 * @property ?string $section_key
 * @property ?string $section_heading
 * @property int $position
 * @property ?string $alt_text
 * @property ?string $caption
 * @property string $status
 * @property ?string $reason
 * @property string $origin
 * @property ?string $updated_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
