<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

final readonly class ProviderResponse
{
    /**
     * @param  list<string>  $images  base64 payloads (image tasks only)
     */
    public function __construct(
        public string $text,
        public Usage $usage,
        public string $finishReason = 'stop',
        public ?string $providerRequestId = null,
        public int $httpStatus = 200,
        public array $images = [],
    ) {}
}
