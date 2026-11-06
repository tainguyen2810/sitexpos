<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class PlanVersionRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'currency' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'size:3', 'alpha'],
            'monthly_price_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'yearly_price_minor' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'trial_credits_mc' => ['sometimes', 'integer', 'min:0', 'max:1000000000000'],
            'included_credits_mc' => ['sometimes', 'integer', 'min:0', 'max:1000000000000'],
            'support_level' => ['nullable', 'in:community,email,priority,dedicated'],
            'feature_flags' => ['sometimes', 'array'],
            'feature_flags.*' => ['boolean'],
            'entitlements' => ['sometimes', 'array'],
            'entitlements.*' => ['nullable', 'integer', 'min:-1', 'max:1000000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
