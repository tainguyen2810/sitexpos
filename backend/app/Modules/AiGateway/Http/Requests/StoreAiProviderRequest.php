<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Requests;

use Illuminate\Validation\Rule;
use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;

/**
 * Custom OpenAI-compatible provider created by a Super Admin. The base URL is
 * additionally SSRF-checked in the controller (and again on every call).
 */
final class StoreAiProviderRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_\-]{1,31}$/', Rule::notIn(AiProvider::DRIVERS), Rule::unique('ai_providers', 'code')],
            'base_url' => ['required', 'string', 'url:http,https', 'max:255'], // scheme enforced by SsrfGuard (https only unless the dev-only SITEX_SSRF_ALLOW_HTTP)
            'api_key' => ['sometimes', 'nullable', 'string', 'min:8', 'max:500'],
            'response_format' => ['sometimes', Rule::in(AiProvider::RESPONSE_FORMATS)],
            'timeout_seconds' => ['sometimes', 'integer', 'min:5', 'max:180'],
            'max_retries' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
