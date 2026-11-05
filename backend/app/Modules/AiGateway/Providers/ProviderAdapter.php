<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

use SiteXPost\Modules\AiGateway\Domain\AiProvider;

interface ProviderAdapter
{
    /** Provider code as stored in ai_providers.code. */
    public function code(): string;

    public function isConfigured(): bool;

    /**
     * Copy of the adapter bound to one ai_providers row (credentials, base URL).
     */
    public function forProvider(AiProvider $provider): static;

    /**
     * Model IDs the provider exposes (Test Connection: free, no generation).
     *
     * @return list<string>
     *
     * @throws ProviderException
     */
    public function listModels(int $timeoutSeconds = 15): array;

    /**
     * Perform exactly ONE HTTP call (no internal retries: the gateway owns the
     * retry policy so paid calls are never retried blindly).
     *
     * @throws ProviderException
     */
    public function generate(ProviderRequest $request): ProviderResponse;
}
