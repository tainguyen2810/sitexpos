<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Support;

use Carbon\CarbonImmutable;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Agent\Domain\AgentConfig;
use SiteXPost\Modules\Agent\Domain\AgentTask;
use SiteXPost\Modules\Agent\Tools\ToolRegistry;
use SiteXPost\Modules\Billing\Contracts\Entitlements;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;

/**
 * Facts the policy engine needs, computed by the server only: the
 * principal's CURRENT role (a demoted or removed user stops the agent),
 * plan entitlement and usage counters.
 */
final class PolicyFacts
{
    public function __construct(private readonly TenantContext $context, private readonly Entitlements $entitlements) {}

    /**
     * @return array{principal_allowed: bool, entitled: bool, month_spend_micro_usd: int, tasks_today: int, refreshes_this_month: int, now: CarbonImmutable}
     */
    public function for(string $tool, AgentConfig $config): array
    {
        $now = CarbonImmutable::now('UTC');
        $counted = ['allow', 'require_approval'];
        $active = ['pending', 'awaiting_approval', 'approved', 'queued', 'running', 'succeeded'];

        return [
            'principal_allowed' => ToolRegistry::exists($tool) && $this->principalAllows($config, $tool),
            'entitled' => $this->entitled(),
            'month_spend_micro_usd' => (int) AgentTask::query()->where('site_id', $config->site_id)->where('created_at', '>=', $now->startOfMonth())
                ->whereIn('decision', $counted)->whereIn('status', $active)
                ->selectRaw('SUM(CASE WHEN actual_cost_micro_usd > 0 THEN actual_cost_micro_usd ELSE estimated_cost_micro_usd END) as s')->value('s'),
            'tasks_today' => AgentTask::query()->where('site_id', $config->site_id)->where('created_at', '>=', $now->setTimezone($config->timezone)->startOfDay()->utc())
                ->whereIn('decision', $counted)->whereIn('status', $active)->whereIn('tool', array_keys(array_filter(ToolRegistry::TOOLS, fn ($t) => $t['writes'])))->count(),
            'refreshes_this_month' => AgentTask::query()->where('site_id', $config->site_id)->where('tool', 'create_refresh_brief')
                ->where('created_at', '>=', $now->startOfMonth())->whereIn('status', ['queued', 'running', 'succeeded', 'approved'])->count(),
            'now' => $now,
        ];
    }

    public function principalAllows(AgentConfig $config, string $tool): bool
    {
        if ($config->principal_user_id === null) {
            return ! ToolRegistry::get($tool)['writes'];
        }
        $member = WorkspaceMember::query()->where('workspace_id', $this->context->workspaceId())->where('user_id', $config->principal_user_id)
            ->where('status', MemberStatus::Active)->first();

        return $member !== null && $member->role->allows(ToolRegistry::permission($tool));
    }

    private function entitled(): bool
    {
        try {
            $this->entitlements->assertActive($this->context->workspaceId(), 'automation');

            return true;
        } catch (ApiException) {
            return false;
        }
    }
}
