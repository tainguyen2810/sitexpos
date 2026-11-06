<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class ChangePlanRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'plan_code' => ['required', 'string', 'max:40'],
            'interval' => ['required', 'in:month,year'],
            'provider' => ['nullable', 'string', 'max:40'],
        ];
    }
}
