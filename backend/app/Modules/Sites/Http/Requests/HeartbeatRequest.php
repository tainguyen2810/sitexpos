<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class HeartbeatRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'site_url' => ['sometimes', 'string', 'max:255'],
            'wp_version' => ['sometimes', 'string', 'max:32'],
            'php_version' => ['sometimes', 'string', 'max:32'],
            'plugin_version' => ['sometimes', 'string', 'max:32'],
            'reason' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
