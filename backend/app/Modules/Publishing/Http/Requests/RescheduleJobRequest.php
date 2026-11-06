<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class RescheduleJobRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'scheduled_at' => ['required', 'date'],
        ];
    }
}
