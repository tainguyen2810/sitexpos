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
            'sections.*.heading' => ['present', 'nullable', 'string', 'max:200'],
            'sections.*.key' => ['nullable', 'string', 'max:40'],
            'sections.*.purpose' => ['nullable', 'string', 'max:500'],
            'sections.*.level' => ['nullable', 'integer', 'in:2,3'],
            'sections.*.blocks' => ['nullable', 'array', 'max:8'],
            'sections.*.blocks.*' => ['string', 'max:24'],
            'sections.*.subheadings' => ['nullable', 'array', 'max:10'],
            'sections.*.subheadings.*' => ['string', 'max:200'],
            'sections.*.key_questions' => ['nullable', 'array', 'max:6'],
            'sections.*.key_questions.*' => ['string', 'max:300'],
            'sections.*.required_facts' => ['nullable', 'array', 'max:12'],
            'sections.*.required_facts.*.fact' => ['required', 'string', 'max:300'],
            'sections.*.required_facts.*.evidence_refs' => ['nullable', 'array', 'max:10'],
            'sections.*.required_facts.*.evidence_refs.*' => ['integer', 'min:1'],
            'sections.*.word_range' => ['nullable', 'array', 'size:2'],
            'sections.*.word_range.*' => ['integer', 'min:30', 'max:2500'],
            'sections.*.internal_links' => ['nullable', 'array', 'max:5'],
            'sections.*.internal_links.*' => ['string', 'max:1000'],
        ];
    }
}
