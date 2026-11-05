<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Requests;

use Illuminate\Validation\Rule;
use SiteXPost\Kernel\Http\Middleware\SetLocale;
use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class UpdateWorkspaceRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'locale' => ['sometimes', 'required', Rule::in(SetLocale::SUPPORTED)],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'status' => ['prohibited'],
            'slug' => ['prohibited'],
            'is_demo' => ['prohibited'],
        ];
    }
}
