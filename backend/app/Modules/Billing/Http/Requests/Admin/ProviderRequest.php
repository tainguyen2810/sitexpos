<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Requests\Admin;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class ProviderRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'adapter' => ['sometimes', 'in:manual_bank,signed_webhook'],
            'name' => ['sometimes', 'string', 'max:120'],
            'is_enabled' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'settings' => ['sometimes', 'array'],
            'settings.bank_name' => ['nullable', 'string', 'max:120'],
            'settings.bank_bin' => ['nullable', 'string', 'regex:/^[0-9]{6}$/'],
            'settings.account_no' => ['nullable', 'string', 'regex:/^[0-9A-Za-z]{4,19}$/'],
            'settings.account_name' => ['nullable', 'string', 'max:120'],
            'settings.timestamp_tolerance_seconds' => ['nullable', 'integer', 'min:30', 'max:3600'],
            'credentials' => ['sometimes', 'array'],
            'credentials.webhook_secret' => ['nullable', 'string', 'min:24', 'max:255'],
        ];
    }
}
