<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Writer\Actions\LearnStyle;
use SiteXPost\Modules\Writer\Pipeline\StyleLearner;

final class LearnStyleRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'site_id' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'content_item_ids' => ['sometimes', 'array', 'max:'.StyleLearner::MAX_SAMPLES],
            'content_item_ids.*' => ['string', 'distinct', 'regex:/^[0-9a-z]{26}$/i'],
            'samples' => ['sometimes', 'array', 'max:10'],
            'samples.*.title' => ['nullable', 'string', 'max:200'],
            'samples.*.text' => ['required', 'string', 'min:300', 'max:20000'],
            // Where the samples came from, shown on the style card ("Toàn bộ blog · 20 bài").
            'origin' => ['sometimes', 'nullable', 'array'],
            'origin.type' => ['required_with:origin', 'string', 'in:'.implode(',', LearnStyle::ORIGINS)],
            'origin.label' => ['nullable', 'string', 'max:200'],
        ];
    }
}
