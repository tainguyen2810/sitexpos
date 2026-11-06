<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`seo_recommendations`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property ?string $opportunity_id
 * @property string $fingerprint
 * @property string $type
 * @property string $target_type
 * @property string $target_ref
 * @property ?string $target_label
 * @property ?string $article_id
 * @property array<string, mixed> $evidence
 * @property string $rationale
 * @property int $priority
 * @property string $benefit_hypothesis
 * @property int $estimated_cost_micro_usd
 * @property string $risk
 * @property ?string $risk_notes
 * @property string $required_approval
 * @property string $status
 * @property array<string, mixed>|null $suggested_value
 * @property string $source
 * @property ?string $agent_run_id
 * @property ?string $decided_by
 * @property ?Carbon $decided_at
 * @property ?string $decision_note
 * @property ?Carbon $applied_at
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SeoRecommendation extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'seo_recommendations';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'priority' => 'integer',
            'estimated_cost_micro_usd' => 'integer',
            'suggested_value' => 'array',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }
}
