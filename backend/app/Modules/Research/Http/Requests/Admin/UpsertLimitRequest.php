<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use SiteXPost\Kernel\Http\Requests\ApiRequest;

final class UpsertLimitRequest extends ApiRequest
{
    // workspace_id is declared explicitly: the platform admin names the target workspace.
    protected function fieldRules(): array
    {
        return [
            'scope' => ['required', Rule::in(['global', 'workspace'])],
            'workspace_id' => ['required_if:scope,workspace', 'prohibited_if:scope,global', 'nullable', 'string', Rule::exists('workspaces', 'id')],
            'max_campaigns' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_runs_per_month' => ['required', 'integer', 'min:0', 'max:10000'],
            'max_ideas_per_run' => ['required', 'integer', 'min:1', 'max:200'],
            'max_competitors_per_campaign' => ['required', 'integer', 'min:0', 'max:50'],
            'max_calendar_items_per_month' => ['required', 'integer', 'min:0', 'max:2000'],
            'max_concurrent_runs' => ['required', 'integer', 'min:1', 'max:20'],
            'max_ai_cost_per_run_micro_usd' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ];
    }
}
