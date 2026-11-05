<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class BatchRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'campaign_id' => ['required', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'content_idea_ids' => ['required', 'array', 'min:1', 'max:100'],
            'content_idea_ids.*' => ['string', 'regex:/^[0-9a-z]{26}$/i'],
            'concurrency' => ['nullable', 'integer', 'min:1', 'max:10'],
            'per_article_budget_micro_usd' => ['nullable', 'integer', 'min:50000'],
            'writing_preset_id' => ['nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            'settings' => ['nullable', 'array'],
            'settings.target_words' => ['nullable', 'integer', 'min:300', 'max:6000'],
            'settings.tone' => ['nullable', 'string', 'max:120'],
            'settings.research_depth' => ['nullable', 'in:basic,standard,deep'],
            'settings.review_mode' => ['nullable', 'in:brief_only,final_only'],
            'settings.approval_mode' => ['nullable', 'in:manual,auto'],
        ];
    }
}
