<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class BillingProfileRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'company_name' => ['nullable', 'string', 'max:191'],
            'tax_code' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z-]*$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+ ().-]*$/'],
        ];
    }
}
