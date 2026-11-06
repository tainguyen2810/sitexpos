<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Requests;

use Illuminate\Validation\Rule;
use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class UpdateAiRouteRequest extends ApiRequest
{
    public const QUEUES = ['research', 'writing', 'factcheck', 'media', 'publishing', 'analytics', 'billing', 'notifications'];

    protected function fieldRules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'queue' => ['sometimes', Rule::in(self::QUEUES)],
            'priority' => ['sometimes', Rule::in(['interactive', 'batch'])],
            'primary_model_id' => ['sometimes', 'string', Rule::exists('ai_models', 'id')],
            'economy_model_id' => ['sometimes', 'nullable', 'string', Rule::exists('ai_models', 'id')],
            'fallback_model_ids' => ['sometimes', 'array', 'max:5'],
            'fallback_model_ids.*' => ['string', 'distinct', Rule::exists('ai_models', 'id')],
            'max_input_tokens' => ['sometimes', 'integer', 'min:100', 'max:1000000'],
            'max_output_tokens' => ['sometimes', 'integer', 'min:1', 'max:128000'],
            'temperature' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2'],
            'timeout_seconds' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:180'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'allow_paid_retry' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
