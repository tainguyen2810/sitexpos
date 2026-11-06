<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class StoreSiteRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        $schemes = config('sitex.ssrf.allow_http') && ! app()->isProduction() ? 'http,https' : 'https';

        return [
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:255', 'url:'.$schemes],
            'content_language' => ['sometimes', 'string', 'in:vi,en'],
        ];
    }
}
