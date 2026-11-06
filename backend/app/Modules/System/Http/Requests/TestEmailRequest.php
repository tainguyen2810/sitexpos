<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class TestEmailRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['to' => ['required', 'email:rfc', 'max:190']];
    }
}
