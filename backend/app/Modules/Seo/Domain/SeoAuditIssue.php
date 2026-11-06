<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`seo_audit_issues`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $seo_audit_run_id
 * @property string $rule
 * @property string $category
 * @property string $severity
 * @property int $weight
 * @property string $points
 * @property string $message
 * @property string $explanation
 * @property ?string $fix
 * @property array<string, mixed>|null $context
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SeoAuditIssue extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'seo_audit_issues';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'points' => 'float',
            'context' => 'array',
        ];
    }
}
