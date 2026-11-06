<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class MailSettingsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['mailer' => ['required', 'in:smtp,env'], 'host' => ['exclude_unless:mailer,smtp', 'required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\\-]+$/'], 'port' => ['exclude_unless:mailer,smtp', 'required', 'integer', 'between:1,65535'], 'encryption' => ['exclude_unless:mailer,smtp', 'required', 'in:tls,ssl,none'], 'username' => ['exclude_unless:mailer,smtp', 'nullable', 'string', 'max:190'], 'password' => ['exclude_unless:mailer,smtp', 'nullable', 'string', 'max:255'], 'from_address' => ['exclude_unless:mailer,smtp', 'required', 'email:rfc', 'max:190'], 'from_name' => ['exclude_unless:mailer,smtp', 'nullable', 'string', 'max:80']];
    }
}
