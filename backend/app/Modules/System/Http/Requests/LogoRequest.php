<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class LogoRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return ['logo' => ['required', 'file', 'max:512', 'mimes:png,jpg,jpeg,webp', 'dimensions:max_width=2000,max_height=2000']];
    }
}
