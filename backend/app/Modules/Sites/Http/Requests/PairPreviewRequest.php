<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class PairPreviewRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'pairing_code' => ['required', 'string', 'max:20'],
            'site_url' => ['required', 'string', 'max:255', 'url:http,https'],
        ];
    }
}
