<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class TestAiModelRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'prompt' => ['sometimes', 'nullable', 'string', 'max:500'],
            'structured' => ['sometimes', 'boolean'],
        ];
    }
}
