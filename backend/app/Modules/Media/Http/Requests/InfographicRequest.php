<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class InfographicRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'site_id' => ['required', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'article_id' => ['nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'type' => ['required', 'in:comparison,steps,features,timeline,bar_chart'],
            'title' => ['required', 'string', 'max:120'],
            'columns' => ['required_if:type,comparison', 'array', 'min:2', 'max:5'],
            'columns.*' => ['string', 'max:60'],
            'rows' => ['required_if:type,comparison', 'array', 'min:1', 'max:10'],
            'rows.*' => ['array', 'max:5'],
            'rows.*.*' => ['nullable', 'string', 'max:80'],
            'items' => ['required_unless:type,comparison', 'array', 'min:1', 'max:10'],
            'items.*.label' => ['required', 'string', 'max:80'],
            'items.*.text' => ['nullable', 'string', 'max:160'],
            'items.*.value' => ['required_if:type,bar_chart', 'nullable', 'numeric'],
            'unit' => ['nullable', 'string', 'max:20'],
            'sources' => ['nullable', 'array', 'max:5'],
            'sources.*.label' => ['required', 'string', 'max:160'],
            'sources.*.url' => ['nullable', 'url:https,http', 'max:500'],
            'customer_provided' => ['nullable', 'boolean'],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
