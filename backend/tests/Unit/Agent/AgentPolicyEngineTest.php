<?php

use Carbon\CarbonImmutable;
use SiteXPost\Modules\Agent\Domain\AgentConfig;
use SiteXPost\Modules\Agent\Domain\AgentPolicy;
use SiteXPost\Modules\Agent\Engine\ScheduleCalculator;
use SiteXPost\Modules\Agent\Policy\AgentPolicyEngine;
use SiteXPost\Modules\Agent\Policy\Decision;
use SiteXPost\Modules\Agent\Tools\ToolRegistry;

function agentCfg(array $attrs = []): AgentConfig
{
    $c = new AgentConfig;
    $c->forceFill($attrs + ['site_id' => 'x', 'enabled' => true, 'autonomy_level' => 1, 'emergency_stop' => false, 'allowed_actions' => [], 'forbidden_actions' => [],
        'max_daily_tasks' => 20, 'max_monthly_ai_spend_micro_usd' => 5_000_000, 'max_monthly_refreshes' => 4, 'publish_window_start' => 8, 'publish_window_end' => 20,
        'timezone' => 'Asia/Ho_Chi_Minh', 'approval_requirements' => [], 'level3_enabled_by' => null, 'lock_version' => 0]);

    return $c;
}

function platformPolicy(array $attrs = []): AgentPolicy
{
    $p = new AgentPolicy;
    $p->forceFill($attrs + ['kill_switch' => false, 'max_autonomy_level' => 3, 'forbidden_tools' => [], 'max_tasks_per_run' => 10, 'lock_version' => 0]);

    return $p;
}

function facts(array $f = []): array
{
    return $f + ['principal_allowed' => true, 'entitled' => true, 'month_spend_micro_usd' => 0, 'tasks_today' => 0, 'refreshes_this_month' => 0,
        'now' => CarbonImmutable::parse('2026-11-06 03:00:00', 'UTC')]; // 10:00 in Ho Chi Minh
}

it('exposes only a closed list of tools: no SQL, shell, HTTP, settings, billing or permission tools', function () {
    $names = array_keys(ToolRegistry::TOOLS);
    foreach (['sql', 'shell', 'http', 'exec', 'settings', 'billing', 'subscription', 'api_key', 'permission', 'member', 'policy'] as $bad) {
        expect(collect($names)->filter(fn ($n) => str_contains($n, $bad))->all())->toBeEmpty();
    }
    expect(ToolRegistry::exists('run_shell'))->toBeFalse();
});

it('denies everything when stopped, disabled or killed — first deny wins', function () {
    $e = new AgentPolicyEngine;
    expect($e->evaluate('read_analytics', agentCfg(), platformPolicy(['kill_switch' => true]), facts())->reasons)->toBe(['platform_kill_switch'])
        ->and($e->evaluate('read_analytics', agentCfg(['emergency_stop' => true]), platformPolicy(), facts())->reasons)->toBe(['emergency_stop'])
        ->and($e->evaluate('read_analytics', agentCfg(['enabled' => false]), platformPolicy(), facts())->reasons)->toBe(['agent_disabled'])
        ->and($e->evaluate('drop_tables', agentCfg(), platformPolicy(), facts())->reasons)->toBe(['unknown_tool']);
});

it('applies autonomy levels: 0 read-only, 1 proposals, 2 drafts, 3 publish only when a person enabled it', function () {
    $e = new AgentPolicyEngine;
    $p = platformPolicy();
    expect($e->evaluate('read_analytics', agentCfg(['autonomy_level' => 0]), $p, facts())->decision)->toBe(Decision::ALLOW)
        ->and($e->evaluate('create_proposal', agentCfg(['autonomy_level' => 0]), $p, facts())->decision)->toBe(Decision::DENY)
        ->and($e->evaluate('create_proposal', agentCfg(['autonomy_level' => 1]), $p, facts())->decision)->toBe(Decision::ALLOW)
        ->and($e->evaluate('create_refresh_brief', agentCfg(['autonomy_level' => 1]), $p, facts())->decision)->toBe(Decision::REQUIRE_APPROVAL)
        ->and($e->evaluate('create_refresh_brief', agentCfg(['autonomy_level' => 2]), $p, facts())->decision)->toBe(Decision::ALLOW)
        ->and($e->evaluate('publish_via_workflow', agentCfg(['autonomy_level' => 2]), $p, facts())->reasons)->toBe(['publish_requires_level_3'])
        // Level 3 configured but never confirmed by a person counts as level 2.
        ->and($e->evaluate('publish_via_workflow', agentCfg(['autonomy_level' => 3]), $p, facts())->decision)->toBe(Decision::DENY)
        ->and($e->evaluate('publish_via_workflow', agentCfg(['autonomy_level' => 3, 'level3_enabled_by' => 'u1']), $p, facts())->decision)->toBe(Decision::ALLOW)
        // The platform cap wins over the site configuration.
        ->and($e->evaluate('publish_via_workflow', agentCfg(['autonomy_level' => 3, 'level3_enabled_by' => 'u1']), platformPolicy(['max_autonomy_level' => 2]), facts())->decision)->toBe(Decision::DENY);
});

it('enforces publish window in the site timezone', function () {
    $e = new AgentPolicyEngine;
    $cfg = agentCfg(['autonomy_level' => 3, 'level3_enabled_by' => 'u1']);
    $night = facts(['now' => CarbonImmutable::parse('2026-11-06 16:00:00', 'UTC')]); // 23:00 local
    expect($e->evaluate('publish_via_workflow', $cfg, platformPolicy(), $night)->reasons)->toBe(['outside_publish_window']);
});

it('enforces budget, daily and monthly limits, entitlements, principal permission and allow/forbid lists', function () {
    $e = new AgentPolicyEngine;
    $p = platformPolicy();
    $l2 = agentCfg(['autonomy_level' => 2]);
    expect($e->evaluate('create_refresh_brief', $l2, $p, facts(['month_spend_micro_usd' => 4_900_000]))->reasons)->toBe(['monthly_budget_exceeded'])
        ->and($e->evaluate('create_proposal', $l2, $p, facts(['tasks_today' => 20]))->reasons)->toBe(['daily_task_limit'])
        ->and($e->evaluate('create_refresh_brief', $l2, $p, facts(['refreshes_this_month' => 4]))->reasons)->toBe(['monthly_refresh_limit'])
        ->and($e->evaluate('create_proposal', $l2, $p, facts(['entitled' => false]))->reasons)->toBe(['plan_not_entitled'])
        ->and($e->evaluate('read_analytics', $l2, $p, facts(['entitled' => false]))->decision)->toBe(Decision::ALLOW) // reading stays allowed
        ->and($e->evaluate('create_proposal', $l2, $p, facts(['principal_allowed' => false]))->reasons)->toBe(['principal_lacks_permission'])
        ->and($e->evaluate('create_proposal', agentCfg(['forbidden_actions' => ['create_proposal']]), $p, facts())->reasons)->toBe(['forbidden_by_site'])
        ->and($e->evaluate('create_proposal', agentCfg(), platformPolicy(['forbidden_tools' => ['create_proposal']]), facts())->reasons)->toBe(['forbidden_by_platform'])
        ->and($e->evaluate('run_seo_audit', agentCfg(['allowed_actions' => ['read_analytics']]), $p, facts())->reasons)->toBe(['not_in_allow_list'])
        ->and($e->evaluate('create_proposal', agentCfg(['approval_requirements' => ['create_proposal' => true]]), $p, facts())->decision)->toBe(Decision::REQUIRE_APPROVAL);
});

it('computes schedules in the site timezone without drift', function () {
    $after = CarbonImmutable::parse('2026-11-06 00:30:00', 'UTC'); // 07:30 in Ho Chi Minh
    expect(ScheduleCalculator::next('daily', '07:00', 'Asia/Ho_Chi_Minh', null, null, $after)->toIso8601ZuluString())->toBe('2026-11-07T00:00:00Z')
        ->and(ScheduleCalculator::next('daily', '08:00', 'Asia/Ho_Chi_Minh', null, null, $after)->toIso8601ZuluString())->toBe('2026-11-06T01:00:00Z')
        ->and(ScheduleCalculator::next('weekly', '07:00', 'Asia/Ho_Chi_Minh', 1, null, $after)->toIso8601ZuluString())->toBe('2026-11-09T00:00:00Z') // next Monday
        ->and(ScheduleCalculator::next('monthly', '07:00', 'Asia/Ho_Chi_Minh', null, 31, $after)->format('d'))->toBe('28'); // clamped to 28
    // DST: 07:00 New York before and after the switch stays 07:00 local.
    $ny = ScheduleCalculator::next('daily', '07:00', 'America/New_York', null, null, CarbonImmutable::parse('2026-10-31 20:00:00', 'UTC'));
    $ny2 = ScheduleCalculator::next('daily', '07:00', 'America/New_York', null, null, $ny->addDay());
    expect($ny->setTimezone('America/New_York')->format('H:i'))->toBe('07:00')->and($ny2->setTimezone('America/New_York')->format('H:i'))->toBe('07:00');
});
