<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Http\Requests;

use Illuminate\Validation\Rule;
use SiteXPost\Kernel\Http\Middleware\SetLocale;
use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class UpdateProfileRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'locale' => ['sometimes', 'required', Rule::in(SetLocale::SUPPORTED)],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'email' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
