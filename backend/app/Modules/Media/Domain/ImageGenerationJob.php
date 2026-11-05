<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`image_generation_jobs`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property ?string $article_id
 * @property ?string $parent_job_id
 * @property int $version
 * @property string $image_type
 * @property ?string $section_key
 * @property string $aspect_ratio
 * @property string $style
 * @property string $quality
 * @property int $image_count
 * @property array<string, mixed>|null $brand_colors
 * @property ?string $prompt_template
 * @property string $prompt
 * @property bool $prompt_edited
 * @property string $status
 * @property int $budget_cap_micro_usd
 * @property int $estimated_cost_micro_usd
 * @property int $cost_micro_usd
 * @property ?string $provider
 * @property ?string $model
 * @property ?string $ai_request_id
 * @property string $call_key
 * @property array<string, mixed>|null $result_asset_ids
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?string $job_run_id
 * @property bool $is_demo
 * @property ?string $created_by
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
