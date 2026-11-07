<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Writer\Pipeline\StyleLearner;
use SiteXPost\Modules\Writer\Support\BuiltinStyles;

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
            'defaults.auto_images' => ['nullable', 'boolean'],
            'defaults.min_images' => ['nullable', 'integer', 'min:0', 'max:6'],
            // Applied style guide (reviewed by the user; usually from a learned draft).
            'style_profile' => ['sometimes', 'nullable', 'array'],
            ...self::styleRules('style_profile'),
            // Copy a ready-made style into this preset (v1.0.9).
            'from_builtin' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', BuiltinStyles::KEYS)],
            'is_demo' => ['prohibited'],
            'style_draft' => ['prohibited'],
            'style_sources' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function styleRules(string $prefix): array
    {
        $rules = [];
        foreach (StyleLearner::TEXT_FIELDS as $f) {
            $rules["{$prefix}.{$f}"] = ['nullable', 'string', 'max:800'];
        }
        foreach (StyleLearner::LIST_FIELDS as $f) {
            $rules["{$prefix}.{$f}"] = ['nullable', 'array', 'max:15'];
            $rules["{$prefix}.{$f}.*"] = ['string', 'max:200'];
        }

        return $rules;
    }
}
