<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $check_key
 * @property string $severity
 * @property string $summary
 * @property ?array<string, string> $channels
 * @property ?Carbon $resolved_at
 * @property ?Carbon $created_at
 */
class SystemAlert extends Model
{
    use HasUlids;

    protected $table = 'system_alerts';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['channels' => 'array', 'resolved_at' => 'datetime'];
    }
}
