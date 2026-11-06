<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class CreditPackageRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'code' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{1,39}$/', 'unique:credit_packages,code'],
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120'],
            'credits_mc' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'integer', 'min:1', 'max:1000000000000'],
            'bonus_credits_mc' => ['sometimes', 'integer', 'min:0', 'max:1000000000000'],
            'price_minor' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'integer', 'min:1', 'max:100000000000'],
            'currency' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'size:3', 'alpha'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'status' => ['sometimes', 'in:active,hidden,archived'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
