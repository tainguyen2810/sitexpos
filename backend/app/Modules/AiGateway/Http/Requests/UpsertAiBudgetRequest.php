<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Requests;

use Illuminate\Validation\Rule;
use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class UpsertAiBudgetRequest extends ApiRequest
{
    // workspace_id is declared explicitly here (platform admin names the target
    // workspace), so the base ApiRequest does not prohibit it.
    protected function fieldRules(): array
    {
        return [
            'scope' => ['required', Rule::in(['global', 'workspace'])],
            'workspace_id' => ['required_if:scope,workspace', 'prohibited_if:scope,global', 'nullable', 'string', Rule::exists('workspaces', 'id')],
            'period' => ['required', Rule::in(['day', 'month'])],
            'limit_micro_usd' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'alert_threshold_pct' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'action_on_exceed' => ['sometimes', Rule::in(['alert', 'pause_batch', 'pause_all'])],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
