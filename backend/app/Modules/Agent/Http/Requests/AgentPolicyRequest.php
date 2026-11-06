<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Http\Requests;

use SiteXPost\Kernel\Http\Requests\ApiRequest;
use SiteXPost\Modules\Agent\Tools\ToolRegistry;

final class AgentPolicyRequest extends ApiRequest
{
    protected function fieldRules(): array
    {
        return [
            'kill_switch' => ['sometimes', 'boolean'],
            'kill_reason' => ['nullable', 'string', 'max:500'],
            'max_autonomy_level' => ['sometimes', 'integer', 'between:0,3'],
            'forbidden_tools' => ['sometimes', 'array'],
            'forbidden_tools.*' => ['string', 'in:'.implode(',', array_keys(ToolRegistry::TOOLS)), 'distinct'],
            'max_tasks_per_run' => ['sometimes', 'integer', 'between:1,50'],
            'max_concurrent_runs' => ['sometimes', 'integer', 'between:1,500'],
            'task_timeout_seconds' => ['sometimes', 'integer', 'between:60,3600'],
            'run_timeout_minutes' => ['sometimes', 'integer', 'between:5,720'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
