<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`seo_health_scores`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $date
 * @property ?int $score
 * @property array<string, mixed> $components
 * @property array<string, mixed> $weights
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SeoHealthScore extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'seo_health_scores';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'components' => 'array',
            'weights' => 'array',
            'is_demo' => 'boolean',
        ];
    }
}
