<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Platform-level AI provider (no tenant data).
 *
 * API key resolution: the key a Super Admin saved (encrypted with Laravel
 * Crypt in `api_key_encrypted`, never returned by the API) wins; otherwise
 * config('sitex.ai.providers.<code>.api_key') (.env), as in Phase 3.
 * `driver` selects the adapter (ProviderRegistry); custom providers use the
 * `openai_compatible` driver with an admin-supplied, SSRF-checked base URL.
 *
 * @property string $id
 * @property string $code
 * @property string $driver
 * @property string|null $base_url
 * @property string|null $api_key_encrypted
 * @property string|null $api_key_last4
 * @property bool $is_custom
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

    public const DRIVERS = ['openai', 'gemini', 'anthropic', 'deepseek', 'openai_compatible', 'fake'];

    /** Structured-output modes of the openai_compatible driver (settings.response_format). */
    public const RESPONSE_FORMATS = ['json_schema', 'json_object', 'none'];

    protected $fillable = ['name', 'is_enabled', 'priority', 'timeout_seconds', 'max_retries', 'circuit_failure_threshold', 'circuit_cooldown_seconds'];

    protected $hidden = ['api_key_encrypted'];

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
            'api_key_encrypted' => 'encrypted',
            'is_custom' => 'boolean',
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
        if ($this->driver === 'fake') {
            return (bool) config('sitex.ai.fake_provider');
        }

        return $this->apiKey() !== null;
    }

    /**
     * Plain API key for the outbound call only (never log or return it).
     */
    public function apiKey(): ?string
    {
        $stored = trim((string) $this->api_key_encrypted);
        if ($stored !== '') {
            return $stored;
        }
        $env = trim((string) config("sitex.ai.providers.{$this->code}.api_key", ''));

        return $env !== '' ? $env : null;
    }

    /**
     * Where the key in use comes from: database, env or none.
     */
    public function apiKeySource(): string
    {
        if (trim((string) $this->api_key_encrypted) !== '') {
            return 'database';
        }

        return $this->apiKey() !== null ? 'env' : 'none';
    }

    public function setApiKey(?string $key): void
    {
        $key = $key !== null ? trim($key) : null;
        $this->api_key_encrypted = $key !== null && $key !== '' ? $key : null;
        $this->api_key_last4 = $key !== null && $key !== '' ? mb_substr($key, -4) : null;
    }

    public function responseFormat(): string
    {
        $mode = $this->settings['response_format'] ?? 'json_object';

        return in_array($mode, self::RESPONSE_FORMATS, true) ? (string) $mode : 'json_object';
    }
}
