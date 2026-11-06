<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

/**
 * Super Admin: enable/disable a research source, tune rate limit / timeout,
 * set or clear its secret (write-only; never returned).
 */
final class UpdateSourceRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'is_enabled' => ['sometimes', 'boolean'],
            'rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:3', 'max:60'],
            'secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            'config' => ['sometimes', 'array'],
            'config.location_code' => ['sometimes', 'integer', 'min:1', 'max:99999999'],
            'reset_failures' => ['sometimes', 'boolean'],
        ];
    }
}
