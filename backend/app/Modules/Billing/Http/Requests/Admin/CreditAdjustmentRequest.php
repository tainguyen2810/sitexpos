<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class CreditAdjustmentRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'amount_mc' => ['required', 'integer', 'not_in:0', 'min:-1000000000000', 'max:1000000000000'],
            'kind' => ['required', 'in:promotional,bonus,correction,refund'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
