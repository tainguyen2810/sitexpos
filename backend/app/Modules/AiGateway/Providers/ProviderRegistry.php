<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

use Illuminate\Contracts\Container\Container;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;

/**
 * Maps ai_providers.driver → adapter class. A provider row is served by
 * `for($provider)`: the driver's adapter bound to that row's credentials and
 * base URL. New providers with an OpenAI-compatible API need no code: a Super
 * Admin adds a row with the `openai_compatible` driver.
 */
final class ProviderRegistry
{
    /** @var array<string, class-string<ProviderAdapter>> */
    public const ADAPTERS = [
        'openai' => OpenAiProvider::class,
        'gemini' => GeminiProvider::class,
        'anthropic' => AnthropicProvider::class,
        'deepseek' => DeepSeekProvider::class,
        'openai_compatible' => OpenAiCompatibleProvider::class,
        'fake' => FakeProvider::class,
    ];

    /** @var array<string, ProviderAdapter> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function has(string $code): bool
    {
        return isset(self::ADAPTERS[$code]);
    }

    public function get(string $code): ProviderAdapter
    {
        if (! $this->has($code)) {
            throw new \InvalidArgumentException("Unknown AI provider [{$code}].");
        }

        return $this->resolved[$code] ??= $this->container->make(self::ADAPTERS[$code]);
    }

    /**
     * Adapter for one provider row (credentials/base URL of that row).
     */
    public function for(AiProvider $provider): ProviderAdapter
    {
        return $this->get($provider->driver)->forProvider($provider);
    }
}
