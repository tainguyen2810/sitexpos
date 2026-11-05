<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

use Illuminate\Contracts\Container\Container;

/**
 * Maps ai_providers.code → adapter. Adding a provider = new adapter class +
 * one line here + a registry row (see AiRegistry).
 */
final class ProviderRegistry
{
    /** @var array<string, class-string<ProviderAdapter>> */
    public const ADAPTERS = [
        'openai' => OpenAiProvider::class,
        'gemini' => GeminiProvider::class,
        'anthropic' => AnthropicProvider::class,
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
}
