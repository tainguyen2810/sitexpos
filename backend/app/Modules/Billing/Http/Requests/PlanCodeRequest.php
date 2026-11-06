<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class PlanCodeRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'plan_code' => ['required', 'string', 'max:40'],
        ];
    }
}
