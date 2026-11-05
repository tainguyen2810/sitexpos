<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

interface ProviderAdapter
{
    /** Provider code as stored in ai_providers.code. */
    public function code(): string;

    public function isConfigured(): bool;

    /**
     * Perform exactly ONE HTTP call (no internal retries: the gateway owns the
     * retry policy so paid calls are never retried blindly).
     *
     * @throws ProviderException
     */
    public function generate(ProviderRequest $request): ProviderResponse;
}
