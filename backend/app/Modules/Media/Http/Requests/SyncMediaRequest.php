<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class SyncMediaRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'site_id' => ['required', 'string', 'regex:/^[0-9a-z]{26}$/i'],
        ];
    }
}
