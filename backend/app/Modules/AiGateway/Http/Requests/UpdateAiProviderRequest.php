<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Requests;

use Illuminate\Validation\Rule;
use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;

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
            // Write-only: stored encrypted, never returned. Empty/omitted = unchanged.
            'api_key' => ['sometimes', 'nullable', 'string', 'min:8', 'max:500'],
            'clear_api_key' => ['sometimes', 'boolean'],
            // Custom (openai_compatible) providers only — checked in the controller.
            'base_url' => ['sometimes', 'string', 'url:http,https', 'max:255'],
            'response_format' => ['sometimes', Rule::in(AiProvider::RESPONSE_FORMATS)],
        ];
    }
}
