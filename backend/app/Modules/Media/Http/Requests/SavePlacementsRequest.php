<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class SavePlacementsRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'items' => ['present', 'array', 'max:40'],
            'items.*.id' => ['nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'items.*.delete' => ['nullable', 'boolean'],
            'items.*.slot' => ['required', 'in:featured,intro,section,comparison,infographic,product'],
            'items.*.section_key' => ['nullable', 'string', 'max:40'],
            'items.*.section_heading' => ['nullable', 'string', 'max:300'],
            'items.*.position' => ['nullable', 'integer', 'min:0', 'max:500'],
            'items.*.media_asset_id' => ['nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'items.*.alt_text' => ['nullable', 'string', 'max:250'],
            'items.*.caption' => ['nullable', 'string', 'max:500'],
            'items.*.status' => ['required', 'in:suggested,accepted,rejected'],
        ];
    }
}
