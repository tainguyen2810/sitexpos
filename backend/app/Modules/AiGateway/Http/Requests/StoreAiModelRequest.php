<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Requests;

use Illuminate\Validation\Rule;
use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class StoreAiModelRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'provider_id' => [$creating ? 'required' : 'prohibited', 'string', Rule::exists('ai_providers', 'id')],
            'model_name' => [$creating ? 'required' : 'prohibited', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\-\/:]+$/'],
            'display_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::in(['text', 'image'])],
            'tier' => ['sometimes', Rule::in(['economy', 'standard', 'premium'])],
            'is_enabled' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.structured_output' => ['sometimes', 'boolean'],
            'capabilities.web_search' => ['sometimes', 'boolean'],
            'capabilities.image_generation' => ['sometimes', 'boolean'],
            'capabilities.temperature' => ['sometimes', 'boolean'],
            'capabilities.vision' => ['sometimes', 'boolean'],
            'capabilities.context_window' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'capabilities.max_output_tokens' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
