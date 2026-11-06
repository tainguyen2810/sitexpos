<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class PresetRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'site_id' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'is_default' => ['sometimes', 'boolean'],
            'tone' => ['sometimes', 'nullable', 'string', 'max:120'],
            'writing_style' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'preferred_terms' => ['sometimes', 'nullable', 'array', 'max:50'],
            'preferred_terms.*' => ['string', 'max:120'],
            'forbidden_phrases' => ['sometimes', 'nullable', 'array', 'max:50'],
            'forbidden_phrases.*' => ['string', 'max:120'],
            'facts' => ['sometimes', 'nullable', 'array', 'max:50'],
            'facts.*.text' => ['required', 'string', 'max:500'],
            'facts.*.provenance' => ['nullable', 'in:user_provided,website_extracted,source_verified,ai_inferred'],
            'facts.*.source_url' => ['nullable', 'string', 'max:1000', 'url:http,https'],
            'target_customers' => ['sometimes', 'nullable', 'array', 'max:20'],
            'target_customers.*' => ['string', 'max:200'],
            'geographic_context' => ['sometimes', 'nullable', 'string', 'max:300'],
            'editorial_guidelines' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'cta' => ['sometimes', 'nullable', 'string', 'max:500'],
            'defaults' => ['sometimes', 'nullable', 'array'],
            'defaults.content_type' => ['nullable', 'string', 'max:24'],
            'defaults.target_words' => ['nullable', 'integer', 'min:300', 'max:6000'],
            'defaults.research_depth' => ['nullable', 'in:basic,standard,deep'],
            'defaults.content_depth' => ['nullable', 'in:basic,standard,deep'],
            'defaults.tone' => ['nullable', 'string', 'max:120'],
            'defaults.budget_limit_micro_usd' => ['nullable', 'integer', 'min:50000'],
            'is_demo' => ['prohibited'],
        ];
    }
}
