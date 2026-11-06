<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class RefundRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
