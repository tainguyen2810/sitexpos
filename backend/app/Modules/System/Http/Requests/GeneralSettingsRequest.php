<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class GeneralSettingsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['brand_name' => ['required', 'string', 'max:80'], 'support_email' => ['required', 'email:rfc', 'max:190'], 'default_locale' => ['required', 'in:vi,en'], 'default_timezone' => ['required', 'timezone:all'], 'company_name' => ['nullable', 'string', 'max:190'], 'company_tax_code' => ['nullable', 'string', 'max:32', 'regex:/^[0-9\\-]+$/'], 'company_address' => ['nullable', 'string', 'max:300'], 'contact_phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+ ().\\-]+$/'], 'contact_email' => ['nullable', 'email:rfc', 'max:190']];
    }
}
