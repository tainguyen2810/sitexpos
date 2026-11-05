<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class DeriveMediaRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'operation' => ['required', 'in:crop,resize'],
            'ratio' => ['nullable', 'required_without_all:box,max_width', 'regex:/^\d{1,2}:\d{1,2}$/'],
            'box' => ['nullable', 'array'],
            'box.x' => ['required_with:box', 'integer', 'min:0'],
            'box.y' => ['required_with:box', 'integer', 'min:0'],
            'box.width' => ['required_with:box', 'integer', 'min:16'],
            'box.height' => ['required_with:box', 'integer', 'min:16'],
            'max_width' => ['nullable', 'integer', 'min:64', 'max:4000'],
        ];
    }
}
