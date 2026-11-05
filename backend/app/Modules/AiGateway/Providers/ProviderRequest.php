<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

/**
 * Provider-neutral request built by the gateway for one attempt.
 */
final readonly class ProviderRequest
{
    /**
     * @param  array<string, mixed>|null  $schema  JSON Schema for structured output
     */
    public function __construct(
        public string $model,
        public string $prompt,
        public ?string $system = null,
        public ?array $schema = null,
        public string $schemaName = 'result',
        public int $maxOutputTokens = 1024,
        public ?float $temperature = null,
        public bool $webSearch = false,
        public int $maxSearches = 3,
        public int $timeoutSeconds = 60,
        public string $kind = 'text',
        public int $imageCount = 1,
        public ?string $imageSize = null,
    ) {}
}
