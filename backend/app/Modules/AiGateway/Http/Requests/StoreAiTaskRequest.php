<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class StoreAiTaskRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:2', 'max:4000'],
            'structured' => ['sometimes', 'boolean'],
            'web_search' => ['sometimes', 'boolean'],
        ];
    }
}
