<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Platform-level AI provider (no tenant data). API keys are NOT stored here:
 * they come from config('sitex.ai.providers.<code>.api_key') (.env).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property bool $is_enabled
 * @property int $priority
 * @property int $timeout_seconds
 * @property int $max_retries
 * @property int $circuit_failure_threshold
 * @property int $circuit_cooldown_seconds
 * @property array<string, mixed>|null $settings
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiProvider extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'is_enabled', 'priority', 'timeout_seconds', 'max_retries', 'circuit_failure_threshold', 'circuit_cooldown_seconds'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'priority' => 'integer',
            'timeout_seconds' => 'integer',
            'max_retries' => 'integer',
            'circuit_failure_threshold' => 'integer',
            'circuit_cooldown_seconds' => 'integer',
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<AiModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'provider_id');
    }

    public function hasApiKey(): bool
    {
        if ($this->code === 'fake') {
            return (bool) config('sitex.ai.fake_provider');
        }

        return trim((string) config("sitex.ai.providers.{$this->code}.api_key", '')) !== '';
    }
}
