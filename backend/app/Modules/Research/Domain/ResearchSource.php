<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * A data source consulted by a research run (with URL and collection time).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $campaign_id
 * @property string $research_run_id
 * @property string $type
 * @property string $label
 * @property ?string $url
 * @property string $status
 * @property ?int $http_status
 * @property bool $robots_allowed
 * @property int $items_count
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?Carbon $fetched_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ResearchSource extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'research_sources';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'robots_allowed' => 'boolean',
            'items_count' => 'integer',
            'http_status' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SourceSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(SourceSnapshot::class);
    }
}
