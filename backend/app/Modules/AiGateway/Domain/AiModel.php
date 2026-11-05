<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Model registry entry. `capabilities` keys: structured_output, web_search,
 * image_generation, temperature, vision, context_window, max_output_tokens.
 *
 * @property string $id
 * @property string $provider_id
 * @property string $model_key
 * @property string $model_name
 * @property string $display_name
 * @property string $kind
 * @property string $tier
 * @property array<string, mixed> $capabilities
 * @property bool $is_enabled
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AiProvider $provider
 */
class AiModel extends Model
{
    use HasUlids;

    public const CAPABILITIES = ['structured_output', 'web_search', 'image_generation', 'temperature', 'vision'];

    protected $fillable = ['display_name', 'kind', 'tier', 'capabilities', 'is_enabled', 'notes'];

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'is_enabled' => 'boolean'];
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    /**
     * @return HasMany<AiModelPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(AiModelPrice::class, 'model_id');
    }

    public function supports(string $capability): bool
    {
        return (bool) ($this->capabilities[$capability] ?? false);
    }

    /**
     * Reasoning/thinking effort sent to providers that support it (DeepSeek:
     * none|low|high|max). null = provider default handling.
     */
    public function reasoningEffort(): ?string
    {
        $v = $this->capabilities['reasoning_effort'] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function maxOutputTokens(): ?int
    {
        $v = $this->capabilities['max_output_tokens'] ?? null;

        return is_numeric($v) ? (int) $v : null;
    }

    /**
     * Price version in force at the given moment (versions are immutable).
     */
    public function priceAt(?Carbon $at = null): ?AiModelPrice
    {
        return $this->prices()
            ->where('effective_from', '<=', $at ?? now())
            ->orderByDesc('effective_from')->orderByDesc('version')
            ->first();
    }
}
