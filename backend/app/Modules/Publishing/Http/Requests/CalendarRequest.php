<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class CalendarRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'site_id' => ['nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'],
        ];
    }
}
