<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class ApproveOutlineRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'sections' => ['nullable', 'array', 'min:1', 'max:30'],
            'sections.*.heading' => ['required', 'string', 'max:200'],
            'sections.*.key' => ['nullable', 'string', 'max:40'],
            'sections.*.purpose' => ['nullable', 'string', 'max:500'],
            'sections.*.block' => ['nullable', 'string', 'max:24'],
            'sections.*.subheadings' => ['nullable', 'array', 'max:10'],
            'sections.*.subheadings.*' => ['string', 'max:200'],
            'sections.*.evidence_refs' => ['nullable', 'array', 'max:20'],
            'sections.*.evidence_refs.*' => ['integer', 'min:1'],
            'sections.*.target_words' => ['nullable', 'integer', 'min:30', 'max:2000'],
            'sections.*.internal_links' => ['nullable', 'array', 'max:5'],
        ];
    }
}
