<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class UpdateScheduleRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'frequency' => ['required', 'string', 'in:daily,weekly,monthly'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'],
            'hour_utc' => ['required', 'integer', 'between:0,23'],
        ];
    }
}
