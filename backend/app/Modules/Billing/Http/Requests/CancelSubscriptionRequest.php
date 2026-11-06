<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class CancelSubscriptionRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
