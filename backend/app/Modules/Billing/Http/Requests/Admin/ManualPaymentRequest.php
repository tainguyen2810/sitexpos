<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class ManualPaymentRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'bank_reference' => ['required', 'string', 'min:3', 'max:120'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
