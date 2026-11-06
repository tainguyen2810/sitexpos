<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Super Admin configuration of one research source (secret encrypted).
 *
 * @property string $id
 * @property string $source
 * @property bool $is_enabled
 * @property array<string, mixed>|null $config
 * @property ?string $secret_encrypted
 * @property int $rate_limit_per_minute
 * @property int $timeout_seconds
 * @property int $failure_count
 * @property ?Carbon $last_failure_at
 * @property ?string $last_error_code
 * @property ?string $updated_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class ResearchSourceSetting extends Model
{
    use HasUlids;

    protected $table = 'research_source_settings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'array',
            'secret_encrypted' => 'encrypted',
            'rate_limit_per_minute' => 'integer',
            'timeout_seconds' => 'integer',
            'failure_count' => 'integer',
            'last_failure_at' => 'datetime',
        ];
    }

    /** @var list<string> */
    protected $hidden = ['secret_encrypted'];

    public function hasSecret(): bool
    {
        return trim((string) $this->secret_encrypted) !== '';
    }
}
