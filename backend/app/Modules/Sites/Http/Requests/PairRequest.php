<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class PairRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'pairing_code' => ['required', 'string', 'max:20'],
            'site_url' => ['required', 'string', 'max:255', 'url:http,https'],
            'rest_url' => ['required', 'string', 'max:255', 'url:http,https'],
            'publish_author' => ['required', 'array'],
            'publish_author.wp_user_id' => ['required', 'integer', 'min:1'],
            'publish_author.display_name' => ['nullable', 'string', 'max:190'],
            'confirmed_by' => ['required', 'array'],
            'confirmed_by.wp_user_id' => ['required', 'integer', 'min:1'],
            'confirmed_by.display_name' => ['nullable', 'string', 'max:190'],
            'environment' => ['required', 'array'],
            'environment.wp_version' => ['required', 'string', 'max:32'],
            'environment.php_version' => ['required', 'string', 'max:32'],
            'environment.plugin_version' => ['required', 'string', 'max:32'],
            'environment.locale' => ['nullable', 'string', 'max:20'],
        ];
    }
}
