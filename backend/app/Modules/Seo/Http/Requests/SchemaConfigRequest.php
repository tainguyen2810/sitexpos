<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Seo\Support\SchemaBuilder;

final class SchemaConfigRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'injection_enabled' => ['sometimes', 'boolean'],
            'enabled_types' => ['sometimes', 'array', 'max:5'],
            'enabled_types.*' => ['string', 'in:'.implode(',', SchemaBuilder::TYPES)],
            'organization_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:1000', 'url:https'],
            'breadcrumbs_supported' => ['sometimes', 'boolean'],
            'allow_canonical_override' => ['sometimes', 'boolean'],
            'allow_robots_override' => ['sometimes', 'boolean'],
        ];
    }
}
