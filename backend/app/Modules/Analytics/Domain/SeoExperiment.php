<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`seo_experiments`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property ?string $article_id
 * @property string $page_hash
 * @property string $page_url
 * @property string $change_type
 * @property ?string $change_ref
 * @property string $method
 * @property Carbon $change_date
 * @property string $baseline_start
 * @property string $baseline_end
 * @property int $observation_days
 * @property string $observation_end
 * @property list<string> $target_metrics
 * @property array<string, mixed>|null $baseline
 * @property array<string, mixed>|null $observed
 * @property string $status
 * @property ?string $outcome
 * @property ?string $outcome_note
 * @property ?string $external_factors
 * @property ?string $created_by
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SeoExperiment extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'seo_experiments';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'change_date' => 'datetime',
            'observation_days' => 'integer',
            'target_metrics' => 'array',
            'baseline' => 'array',
            'observed' => 'array',
            'is_demo' => 'boolean',
        ];
    }
}
