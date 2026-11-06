<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 8 tenant row (`seo_opportunities`), see docs/DATABASE.md §Phase 8.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $rule_code
 * @property string $rule_version
 * @property string $fingerprint
 * @property string $target_type
 * @property string $target_ref
 * @property ?string $target_label
 * @property ?string $article_id
 * @property array<string, mixed> $evidence
 * @property string $detection_rule
 * @property string $severity
 * @property string $impact
 * @property string $impact_basis
 * @property int $confidence
 * @property string $confidence_explanation
 * @property string $recommended_action
 * @property int $estimated_ai_cost_micro_usd
 * @property string $status
 * @property ?string $reviewed_by
 * @property ?Carbon $reviewed_at
 * @property ?string $review_note
 * @property Carbon $first_detected_at
 * @property Carbon $last_detected_at
 * @property int $detections
 * @property bool $is_demo
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SeoOpportunity extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'seo_opportunities';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'confidence' => 'integer',
            'estimated_ai_cost_micro_usd' => 'integer',
            'reviewed_at' => 'datetime',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'detections' => 'integer',
            'is_demo' => 'boolean',
        ];
    }
}
