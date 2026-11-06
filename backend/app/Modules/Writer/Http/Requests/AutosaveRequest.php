<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class AutosaveRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'content_html' => ['required', 'string', 'max:600000'],
            'base_version_id' => ['required', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
