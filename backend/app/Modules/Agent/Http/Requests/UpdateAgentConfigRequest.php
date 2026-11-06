<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Agent\Support\SiteRef;
use SiteXPost\Modules\Agent\Tools\ToolRegistry;

final class UpdateAgentConfigRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        SiteRef::resolve((string) $this->route('site'));
    }

    protected function fieldRules(): array
    {
        $tools = implode(',', array_keys(ToolRegistry::TOOLS));

        return [
            'enabled' => ['sometimes', 'boolean'],
            'autonomy_level' => ['sometimes', 'integer', 'between:0,3'],
            'confirm_level3' => ['sometimes', 'boolean'],
            'allowed_actions' => ['sometimes', 'array', 'max:20'],
            'allowed_actions.*' => ['string', 'in:'.$tools, 'distinct'],
            'forbidden_actions' => ['sometimes', 'array', 'max:20'],
            'forbidden_actions.*' => ['string', 'in:'.$tools, 'distinct'],
            'max_daily_tasks' => ['sometimes', 'integer', 'between:0,200'],
            'max_monthly_ai_spend_micro_usd' => ['sometimes', 'integer', 'between:0,1000000000'],
            'max_monthly_refreshes' => ['sometimes', 'integer', 'between:0,200'],
            'publish_window_start' => ['sometimes', 'integer', 'between:0,23'],
            'publish_window_end' => ['sometimes', 'integer', 'between:0,24'],
            'timezone' => ['sometimes', 'timezone:all'],
            'approval_requirements' => ['sometimes', 'array:'.$tools],
            'approval_requirements.*' => ['boolean'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
