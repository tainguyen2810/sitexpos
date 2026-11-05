<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class UpdateMediaRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:250'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:500'],
            'attribution' => ['sometimes', 'nullable', 'string', 'max:500'],
            'license' => ['sometimes', 'in:owned,customer_provided,licensed_stock'],
        ];
    }
}
