<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`seo_link_suggestions`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 */
class SeoLinkSuggestion extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'seo_link_suggestions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'target_wp_post_id' => 'integer',
            'paragraph_index' => 'integer',
            'relevance' => 'integer',
            'signals' => 'array',
            'verified_at' => 'datetime',
        ];
    }
}
