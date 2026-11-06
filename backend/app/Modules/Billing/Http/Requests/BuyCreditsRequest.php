<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class BuyCreditsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'package_code' => ['required', 'string', 'max:40'],
            'provider' => ['nullable', 'string', 'max:40'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
