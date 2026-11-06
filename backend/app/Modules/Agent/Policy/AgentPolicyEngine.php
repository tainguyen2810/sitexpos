<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Policy;

use Carbon\CarbonImmutable;
use SiteXPost\Modules\Agent\Domain\AgentConfig;
use SiteXPost\Modules\Agent\Domain\AgentPolicy;
use SiteXPost\Modules\Agent\Tools\ToolRegistry;

/**
 * Server-side policy engine (AGENT-POLICY-ENGINE.md). Pure function of the
 * platform policy, the site configuration and facts computed by the
 * server (principal permission, budget, usage, clock). Nothing the model
 * writes can change its outcome: the agent proposes, this decides.
 *
 * Evaluation order — the first deny wins:
 *  1 kill switch / emergency stop / agent disabled
 *  2 unknown tool
 *  3 forbidden by platform or site, or outside the site's allow-list
 *  4 principal (the human the agent acts for) lacks the permission
 *  5 plan entitlement (automation) for writing tools
 *  6 budget: monthly AI spend cap, daily task cap, monthly refresh cap
 *  7 autonomy level: level 0 never writes; below the tool's level → approval
 *  8 publish window (site timezone) for publish_via_workflow
 *  9 per-tool approval requirement configured by the site
 */
final class AgentPolicyEngine
{
    /**
     * @param  array{principal_allowed: bool, entitled: bool, month_spend_micro_usd: int, tasks_today: int, refreshes_this_month: int, now?: CarbonImmutable}  $facts
     */
    public function evaluate(string $tool, AgentConfig $config, AgentPolicy $platform, array $facts): Decision
    {
        if ($platform->kill_switch) {
            return Decision::deny(['platform_kill_switch']);
        }
        if ($config->emergency_stop) {
            return Decision::deny(['emergency_stop']);
        }
        if (! $config->enabled) {
            return Decision::deny(['agent_disabled']);
        }
        if (! ToolRegistry::exists($tool)) {
            return Decision::deny(['unknown_tool']);
        }
        $spec = ToolRegistry::get($tool);
        if (in_array($tool, $platform->forbidden_tools ?? [], true)) {
            return Decision::deny(['forbidden_by_platform']);
        }
        if (in_array($tool, $config->forbidden_actions ?? [], true)) {
            return Decision::deny(['forbidden_by_site']);
        }
        $allowed = $config->allowed_actions ?? [];
        if ($allowed !== [] && ! in_array($tool, $allowed, true)) {
            return Decision::deny(['not_in_allow_list']);
        }
        if (! $facts['principal_allowed']) {
            return Decision::deny(['principal_lacks_permission']);
        }
        if ($spec['writes'] && ! $facts['entitled']) {
            return Decision::deny(['plan_not_entitled']);
        }
        if ($spec['cost'] > 0 && $config->max_monthly_ai_spend_micro_usd < $facts['month_spend_micro_usd'] + $spec['cost']) {
            return Decision::deny(['monthly_budget_exceeded']);
        }
        if ($spec['writes'] && $facts['tasks_today'] >= $config->max_daily_tasks) {
            return Decision::deny(['daily_task_limit']);
        }
        if ($tool === 'create_refresh_brief' && $facts['refreshes_this_month'] >= $config->max_monthly_refreshes) {
            return Decision::deny(['monthly_refresh_limit']);
        }

        $level = self::effectiveLevel($config, $platform);
        $reasons = [];
        if ($spec['writes'] && $level === 0) {
            return Decision::deny(['autonomy_level_0_read_only']);
        }
        if ($tool === 'publish_via_workflow') {
            if ($level < 3 || $config->level3_enabled_by === null) {
                return Decision::deny(['publish_requires_level_3']);
            }
            if (! self::inWindow($config, $facts['now'] ?? CarbonImmutable::now('UTC'))) {
                return Decision::deny(['outside_publish_window']);
            }
        }
        if ($level < $spec['min_level']) {
            $reasons[] = 'autonomy_level_'.$level.'_requires_approval';
        }
        if ((bool) (($config->approval_requirements ?? [])[$tool] ?? false)) {
            $reasons[] = 'site_requires_approval';
        }

        return $reasons === [] ? new Decision(Decision::ALLOW, ['policy_allows']) : new Decision(Decision::REQUIRE_APPROVAL, $reasons);
    }

    public static function effectiveLevel(AgentConfig $config, AgentPolicy $platform): int
    {
        $level = min($config->autonomy_level, $platform->max_autonomy_level);
        // Level 3 counts only when a person explicitly enabled it (never by default, never by the agent).
        if ($level >= 3 && $config->level3_enabled_by === null) {
            $level = 2;
        }

        return max(0, $level);
    }

    public static function inWindow(AgentConfig $config, CarbonImmutable $now): bool
    {
        $hour = (int) $now->setTimezone($config->timezone)->format('G');
        $start = $config->publish_window_start;
        $end = $config->publish_window_end;

        return $start <= $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
    }

    /**
     * Snapshot stored on every run / task so decisions can be audited later.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(AgentConfig $config, AgentPolicy $platform): array
    {
        return [
            'autonomy_level' => $config->autonomy_level, 'effective_level' => self::effectiveLevel($config, $platform),
            'platform_max_level' => $platform->max_autonomy_level, 'platform_forbidden' => $platform->forbidden_tools ?? [],
            'allowed_actions' => $config->allowed_actions ?? [], 'forbidden_actions' => $config->forbidden_actions ?? [],
            'max_daily_tasks' => $config->max_daily_tasks, 'max_monthly_ai_spend_micro_usd' => $config->max_monthly_ai_spend_micro_usd,
            'max_monthly_refreshes' => $config->max_monthly_refreshes, 'publish_window' => [$config->publish_window_start, $config->publish_window_end, $config->timezone],
            'approval_requirements' => $config->approval_requirements ?? [], 'level3_enabled' => $config->level3_enabled_by !== null,
            'config_version' => $config->lock_version, 'platform_version' => $platform->lock_version,
        ];
    }
}
