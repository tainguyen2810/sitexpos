<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`seo_audit_runs`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property string $site_id
 * @property ?string $article_version_id
 * @property string $content_hash
 * @property string $rules_version
 * @property string $status
 * @property int $score
 * @property int $pass_count
 * @property int $warning_count
 * @property int $error_count
 * @property int $blocked_count
 * @property bool $links_checked
 * @property array<string, mixed>|null $summary
 * @property bool $is_demo
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
