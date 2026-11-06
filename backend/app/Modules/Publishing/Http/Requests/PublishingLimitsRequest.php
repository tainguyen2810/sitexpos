<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class PublishingLimitsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'scope_key' => ['required', 'string', 'regex:/^(global|ws:[0-9A-Za-z]{26})$/'],
            'max_publishes_per_day' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'max_bulk_items' => ['nullable', 'integer', 'min:1', 'max:500'],
            'max_schedule_days_ahead' => ['nullable', 'integer', 'min:1', 'max:730'],
            'auto_publish_allowed' => ['required', 'boolean'],
            'publishing_enabled' => ['required', 'boolean'],
        ];
    }
}
