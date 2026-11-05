<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class ApproveBriefRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'data' => ['nullable', 'array'],
            'data.title_suggestions' => ['sometimes', 'array', 'max:6'],
            'data.title_suggestions.*' => ['string', 'max:200'],
            'data.audience' => ['sometimes', 'nullable', 'string', 'max:300'],
            'data.main_question' => ['sometimes', 'nullable', 'string', 'max:300'],
            'data.supporting_questions' => ['sometimes', 'array', 'max:15'],
            'data.supporting_questions.*' => ['string', 'max:300'],
            'data.content_angle' => ['sometimes', 'nullable', 'string', 'max:800'],
            'data.differentiation' => ['sometimes', 'nullable', 'string', 'max:800'],
            'data.structure' => ['sometimes', 'array', 'max:30'],
            'data.structure.*.heading' => ['required', 'string', 'max:200'],
            'data.structure.*.level' => ['nullable', 'integer', 'in:2,3'],
            'data.structure.*.purpose' => ['nullable', 'string', 'max:300'],
            'data.key_facts' => ['sometimes', 'array', 'max:40'],
            'data.key_facts.*.fact' => ['required', 'string', 'max:500'],
            'data.key_facts.*.evidence_refs' => ['array', 'max:10'],
            'data.key_facts.*.evidence_refs.*' => ['integer', 'min:1'],
            'data.missing_information' => ['sometimes', 'array', 'max:30'],
            'data.missing_information.*' => ['string', 'max:500'],
            'data.internal_links' => ['sometimes', 'array', 'max:15'],
            'data.internal_links.*.url' => ['required', 'string', 'max:1000'],
            'data.internal_links.*.anchor' => ['nullable', 'string', 'max:120'],
            'data.cta' => ['sometimes', 'nullable', 'string', 'max:500'],
            'data.faq' => ['sometimes', 'array', 'max:15'],
        ];
    }
}
