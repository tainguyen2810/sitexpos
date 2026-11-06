<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class CompleteRefundRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'succeeded' => ['required', 'boolean'],
            'external_ref' => ['required_if:succeeded,true', 'nullable', 'string', 'max:120'],
            'failure_reason' => ['required_if:succeeded,false', 'nullable', 'string', 'max:255'],
        ];
    }
}
