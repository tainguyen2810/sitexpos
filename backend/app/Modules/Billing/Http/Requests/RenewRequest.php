<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class RenewRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'provider' => ['nullable', 'string', 'max:40'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
