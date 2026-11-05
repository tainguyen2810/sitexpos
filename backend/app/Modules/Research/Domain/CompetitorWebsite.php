<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A competitor site added by the customer (public pages only).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $campaign_id
 * @property string $name
 * @property string $url
 * @property string $url_hash
 * @property string $status
 * @property bool $robots_allowed
 * @property int $pages_crawled
 * @property ?string $last_error_code
 * @property ?string $last_error_message
 * @property ?Carbon $last_crawled_at
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
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
