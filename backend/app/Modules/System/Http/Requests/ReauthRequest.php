<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class ReauthRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['password' => ['required', 'string', 'current_password:web'], 'backup_first' => ['sometimes', 'boolean']];
    }
}
