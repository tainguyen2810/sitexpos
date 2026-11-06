<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class GenerateImageRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'site_id' => ['required_without:article_id', 'nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'article_id' => ['nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'section_key' => ['nullable', 'string', 'max:40'],
            'image_type' => ['required', 'in:featured,section,concept,infographic_background'],
            'aspect_ratio' => ['nullable', 'in:1:1,16:9,3:2,4:3,2:3,9:16'],
            'style' => ['nullable', 'in:flat_illustration,isometric,line_art,watercolor,3d_render,editorial_concept'],
            'quality' => ['nullable', 'in:standard,high'],
            'image_count' => ['nullable', 'integer', 'min:1', 'max:4'],
            'brand_colors' => ['nullable', 'array', 'max:5'],
            'brand_colors.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'template' => ['nullable', 'in:editorial,section,concept,background'],
            'context' => ['nullable', 'string', 'max:500'],
            'title' => ['nullable', 'string', 'max:200'],
            'prompt' => ['nullable', 'string', 'max:3000'],
            'budget_cap_micro_usd' => ['nullable', 'integer', 'min:1000', 'max:50000000'],
        ];
    }
}
