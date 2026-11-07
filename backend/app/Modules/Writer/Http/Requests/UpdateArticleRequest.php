<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;

/** Article settings editable after creation (no content, no status). */
final class UpdateArticleRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:5', 'max:300'],
            'review_mode' => ['sometimes', 'in:step_by_step,brief_only,final_only'],
            'approval_mode' => ['sometimes', 'in:manual,auto'],
            'budget_limit_micro_usd' => ['sometimes', 'integer', 'min:50000'],
            'writing_preset_id' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-z]{26}$/i'],
            // Research settings: used by the next research run.
            'source_urls' => ['sometimes', 'array', 'max:20'],
            'source_urls.*' => ['string', 'max:1000', 'url:http,https'],
            'allow_web_search' => ['sometimes', 'boolean'],
            'ai_source_discovery' => ['sometimes', 'boolean'],
            'status' => ['prohibited'],
            'spent_micro_usd' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'content_html' => ['prohibited'],
        ];
    }
}
