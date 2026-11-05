<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SiteXPost\Kernel\Tenancy\BelongsToWorkspace;

/**
 * Result of one signed GET /health call to the plugin. Kept 30 days.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $site_id
 * @property Carbon $checked_at
 * @property bool $ok
 * @property int|null $http_status
 * @property int|null $latency_ms
 * @property string|null $error_code
 * @property array<string, mixed>|null $details
 * @property Carbon $created_at
 */
class SiteHealthCheck extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'ok' => 'boolean',
            'http_status' => 'integer',
            'latency_ms' => 'integer',
            'details' => 'array',
        ];
    }
}
