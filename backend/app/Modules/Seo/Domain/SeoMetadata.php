<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`seo_metadata`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class SeoMetadata extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'seo_metadata';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'secondary_keywords' => 'array',
            'schema_types' => 'array',
            'manual_fields' => 'array',
            'robots_noindex' => 'boolean',
            'robots_nofollow' => 'boolean',
            'lock_version' => 'integer',
        ];
    }
}
