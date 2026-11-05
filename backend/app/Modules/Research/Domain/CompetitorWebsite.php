<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A competitor site added by the customer (public pages only).
 *
 * @property string $id
 */
class CompetitorWebsite extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'competitor_websites';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'robots_allowed' => 'boolean',
            'pages_crawled' => 'integer',
            'last_crawled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CompetitorFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(CompetitorFinding::class);
    }
}
