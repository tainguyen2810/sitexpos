<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class UpdateAiProviderRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'is_enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:5', 'max:180'],
            'max_retries' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'circuit_failure_threshold' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'circuit_cooldown_seconds' => ['sometimes', 'integer', 'min:10', 'max:3600'],
            // Never accepted from the UI: credentials and endpoints live in .env only.
            'api_key' => ['prohibited'],
            'base_url' => ['prohibited'],
        ];
    }
}
