<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Website Knowledge Profile of a connected site (one per site).
 *
 * @property string $id
 */
class WebsiteProfile extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'website_profiles';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'covered_topics' => 'array',
            'sitemap_url_count' => 'integer',
            'last_crawled_at' => 'datetime',
            'analyzed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WebsiteKnowledge, $this>
     */
    public function knowledge(): HasMany
    {
        return $this->hasMany(WebsiteKnowledge::class);
    }
}
