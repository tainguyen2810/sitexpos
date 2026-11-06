<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class ReviewTransactionRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'order_id' => ['sometimes', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
