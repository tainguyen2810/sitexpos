<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Seo\Support\SchemaBuilder;

final class UpdateMetadataRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:0'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9\- ]+$/'],
            'canonical_url' => ['sometimes', 'nullable', 'string', 'max:1000', 'url:https,http'],
            'focus_keyword' => ['sometimes', 'nullable', 'string', 'max:190'],
            'secondary_keywords' => ['sometimes', 'nullable', 'array', 'max:10'],
            'secondary_keywords.*' => ['string', 'max:120'],
            'og_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'og_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'social_image_asset_id' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'robots_noindex' => ['sometimes', 'boolean'],
            'robots_nofollow' => ['sometimes', 'boolean'],
            'schema_types' => ['sometimes', 'array', 'max:5'],
            'schema_types.*' => ['string', 'in:'.implode(',', SchemaBuilder::TYPES)],
        ];
    }
}
