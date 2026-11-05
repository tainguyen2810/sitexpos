<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`seo_audit_issues`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
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
