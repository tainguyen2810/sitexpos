<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`seo_audit_runs`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class SeoAuditRun extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'seo_audit_runs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'pass_count' => 'integer',
            'warning_count' => 'integer',
            'error_count' => 'integer',
            'blocked_count' => 'integer',
            'links_checked' => 'boolean',
            'summary' => 'array',
            'is_demo' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SeoAuditIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(SeoAuditIssue::class, 'seo_audit_run_id')->orderByRaw("CASE severity WHEN 'BLOCKED' THEN 0 WHEN 'ERROR' THEN 1 WHEN 'WARNING' THEN 2 ELSE 3 END")->orderBy('rule');
    }
}
