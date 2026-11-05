<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Gateway;

use SiteXPost\Modules\AiGateway\Providers\Usage;

final readonly class AiResult
{
    /**
     * @param  list<array<string, mixed>>  $attempts
     * @param  list<string>  $images
     */
    public function __construct(
        public string $text,
        public mixed $json,
        public Usage $usage,
        public int $costMicroUsd,
        public string $provider,
        public string $model,
        public string $modelKey,
        public string $requestId,
        public string $finishReason,
        public bool $usedFallback,
        public bool $downgraded,
        public array $attempts,
        public array $images = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'model_key' => $this->modelKey,
            'request_id' => $this->requestId,
            'finish_reason' => $this->finishReason,
            'used_fallback' => $this->usedFallback,
            'downgraded' => $this->downgraded,
            'usage' => $this->usage->toArray(),
            'cost_micro_usd' => $this->costMicroUsd,
            'attempts' => $this->attempts,
        ];
    }
}
