<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Phase 6 tenant row (`media_sources`), see docs/DATABASE.md §Phase 6.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $type
 * @property bool $enabled
 * @property string $status
 * @property array<string, mixed>|null $config
 * @property int $items_count
 * @property ?string $last_error_code
 * @property ?Carbon $last_synced_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class MediaSource extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $table = 'media_sources';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'array',
            'items_count' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }
}
