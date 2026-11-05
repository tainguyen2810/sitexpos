<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`image_generation_jobs`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class ImageGenerationJob extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'image_generation_jobs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'brand_colors' => 'array',
            'result_asset_ids' => 'array',
            'prompt_edited' => 'boolean',
            'image_count' => 'integer',
            'version' => 'integer',
            'budget_cap_micro_usd' => 'integer',
            'estimated_cost_micro_usd' => 'integer',
            'cost_micro_usd' => 'integer',
            'is_demo' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
