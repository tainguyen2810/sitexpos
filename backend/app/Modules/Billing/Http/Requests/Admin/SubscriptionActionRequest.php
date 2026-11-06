<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class SubscriptionActionRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'action' => ['required', 'in:pause,resume,suspend,unsuspend,extend,cancel'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'days' => ['required_if:action,extend', 'nullable', 'integer', 'min:1', 'max:366'],
        ];
    }
}
