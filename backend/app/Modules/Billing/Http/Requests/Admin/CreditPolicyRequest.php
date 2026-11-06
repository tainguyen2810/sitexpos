<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class CreditPolicyRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'micro_usd_per_credit' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'markup_bps' => ['required', 'integer', 'min:0', 'max:100000'],
            'min_charge_mc' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'effective_from' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
