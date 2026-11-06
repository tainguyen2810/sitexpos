<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $from_version
 * @property string $to_version
 * @property string $status
 * @property ?list<string> $migrations
 * @property ?list<array<string, mixed>> $steps
 * @property ?string $error
 * @property string $requested_by
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $created_at
 */
class UpgradeRun extends Model
{
    use HasUlids;

    protected $table = 'system_upgrade_runs';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'migrations' => 'array',
            'steps' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
