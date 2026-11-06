<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class PlanRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'code' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{1,39}$/', 'unique:plans,code'],
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'in:draft,active,hidden,archived'],
            'is_free' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
