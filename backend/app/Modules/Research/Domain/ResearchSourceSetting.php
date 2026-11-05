<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Super Admin configuration of one research source (secret encrypted).
 *
 * @property string $id
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
