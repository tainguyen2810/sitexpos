<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`seo_link_suggestions`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $article_id
 * @property string $site_id
 * @property int $target_wp_post_id
 * @property string $target_post_type
 * @property string $target_url
 * @property string $target_title
 * @property string $anchor_text
 * @property ?string $section_key
 * @property int $paragraph_index
 * @property int $relevance
 * @property array<string, mixed>|null $signals
 * @property string $reason
 * @property string $status
 * @property ?Carbon $verified_at
 * @property ?string $decided_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
