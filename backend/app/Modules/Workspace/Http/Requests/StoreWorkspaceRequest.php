<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Requests;

use Illuminate\Validation\Rule;
use SiteXPost\Kernel\Http\Middleware\SetLocale;
use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class StoreWorkspaceRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'locale' => ['nullable', Rule::in(SetLocale::SUPPORTED)],
            'timezone' => ['nullable', 'timezone:all'],
        ];
    }
}
