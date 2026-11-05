<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`schema_configs`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class SchemaConfig extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'schema_configs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'injection_enabled' => 'boolean',
            'enabled_types' => 'array',
            'breadcrumbs_supported' => 'boolean',
            'allow_canonical_override' => 'boolean',
            'allow_robots_override' => 'boolean',
        ];
    }
}
