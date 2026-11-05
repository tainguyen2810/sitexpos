<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Writer\Actions\CreateArticle;

/** Wizard input (manual topic or Phase 4 idea). */
final class StoreArticleRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        $ulid = ['nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'];

        return [
            'site_id' => ['required', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'content_idea_id' => $ulid,
            'campaign_id' => $ulid,
            'writing_preset_id' => $ulid,
            'topic' => ['required_without:content_idea_id', 'nullable', 'string', 'min:5', 'max:300'],
            'title' => ['nullable', 'string', 'min:5', 'max:300'],
            'primary_keyword' => ['nullable', 'string', 'min:2', 'max:190'],
            'secondary_keywords' => ['nullable', 'array', 'max:15'],
            'secondary_keywords.*' => ['string', 'max:120'],
            'search_intent' => ['nullable', 'in:informational,commercial,transactional,navigational'],
            'content_type' => ['nullable', 'in:'.implode(',', CreateArticle::CONTENT_TYPES)],
            'audience' => ['nullable', 'string', 'max:300'],
            'tone' => ['nullable', 'string', 'max:120'],
            'target_words' => ['nullable', 'integer', 'min:300', 'max:6000'],
            'content_depth' => ['nullable', 'in:basic,standard,deep'],
            'research_depth' => ['nullable', 'in:basic,standard,deep'],
            'internal_links' => ['nullable', 'array'],
            'internal_links.enabled' => ['boolean'],
            'internal_links.max' => ['integer', 'min:0', 'max:15'],
            'brand_rules' => ['nullable', 'array', 'max:20'],
            'brand_rules.*' => ['string', 'max:300'],
            'source_urls' => ['nullable', 'array', 'max:20'],
            'source_urls.*' => ['string', 'max:1000', 'url:http,https'],
            'brand_domains' => ['nullable', 'array', 'max:10'],
            'brand_domains.*' => ['string', 'max:190', 'regex:/^[a-z0-9.-]+\\.[a-z]{2,}$/i'],
            'cta' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'ai_source_discovery' => ['nullable', 'boolean'],
            'allow_web_search' => ['nullable', 'boolean'],
            'ai_review_notes' => ['nullable', 'boolean'],
            'review_mode' => ['nullable', 'in:step_by_step,brief_only,final_only'],
            'approval_mode' => ['nullable', 'in:manual,auto'],
            'budget_limit_micro_usd' => ['nullable', 'integer', 'min:50000'],
            'language' => ['nullable', 'in:vi,en'],
            'country' => ['nullable', 'string', 'size:2'],
            'start' => ['nullable', 'boolean'],
            'mode' => ['prohibited'],
            'status' => ['prohibited'],
            'spent_micro_usd' => ['prohibited'],
            'is_demo' => ['prohibited'],
        ];
    }
}
