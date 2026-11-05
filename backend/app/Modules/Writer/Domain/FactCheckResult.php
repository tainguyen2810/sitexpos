<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Verdict for one claim (model, rule or human reviewer).
 *
 * @property string $id
 * @property string $workspace_id
 * @property ?string $fact_check_run_id
 * @property string $article_claim_id
 * @property string $verdict
 * @property list<array<string, mixed>>|null $supporting
 * @property list<array<string, mixed>>|null $contradicting
 * @property ?string $date_context
 * @property ?string $explanation
 * @property array<string, mixed>|null $rule_notes
 * @property string $reviewer_type
 * @property ?string $reviewer_model
 * @property ?string $reviewer_user_id
 * @property Carbon $reviewed_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class FactCheckResult extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'fact_check_results';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'supporting' => 'array',
            'contradicting' => 'array',
            'rule_notes' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }
}
