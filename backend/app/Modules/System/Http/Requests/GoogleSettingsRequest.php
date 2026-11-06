<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class GoogleSettingsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['client_id' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\\-_]+$/'], 'client_secret' => ['nullable', 'string', 'max:255'], 'ga4_enabled' => ['boolean'], 'clear' => ['boolean']];
    }
}
