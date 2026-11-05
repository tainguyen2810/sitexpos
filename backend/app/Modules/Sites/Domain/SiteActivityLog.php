<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Connector activity / error log shown on the site detail screen.
 * Messages are safe to display (never contain secrets or raw bodies).
 *
 * @property int $id
 * @property string $workspace_id
 * @property string $site_id
 * @property string $direction out = Cloud → WP, in = WP → Cloud
 * @property string $operation
 * @property string $level info|warning|error
 * @property int|null $http_status
 * @property string|null $error_code
 * @property string|null $message
 * @property int|null $latency_ms
 * @property string|null $request_id
 * @property Carbon $created_at
 */
class SiteActivityLog extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
