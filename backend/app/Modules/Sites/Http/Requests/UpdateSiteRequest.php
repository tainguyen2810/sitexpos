<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class UpdateSiteRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'content_language' => ['sometimes', 'string', 'in:vi,en'],
            'settings' => ['sometimes', 'array:default_post_status,default_category_id'],
            'settings.default_post_status' => ['sometimes', 'string', 'in:draft,pending,publish'],
            'settings.default_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
