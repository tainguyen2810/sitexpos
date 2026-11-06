<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Support;

use SiteXPost\Modules\Agent\Domain\AgentConfig;
use SiteXPost\Modules\Agent\Domain\AgentPolicy;

/**
 * Loads (or lazily creates with safe defaults) the platform policy and a
 * site's agent configuration. Defaults: disabled, autonomy level 1.
 */
final class AgentSettings
{
    public static function platform(): AgentPolicy
    {
        $p = AgentPolicy::query()->where('scope', 'global')->first();
        if ($p === null) {
            $p = new AgentPolicy;
            $p->forceFill(['scope' => 'global', 'max_tasks_per_run' => (int) config('sitex.agent.max_tasks_per_run', 10),
                'task_timeout_seconds' => (int) config('sitex.agent.task_timeout_seconds', 900), 'run_timeout_minutes' => (int) config('sitex.agent.run_timeout_minutes', 120)])->save();
            $p->refresh();
        }

        return $p;
    }

    public static function site(string $siteId, ?string $timezone = null): AgentConfig
    {
        $c = AgentConfig::query()->where('site_id', $siteId)->first();
        if ($c === null) {
            $c = new AgentConfig;
            $c->forceFill(['site_id' => $siteId, 'enabled' => false, 'autonomy_level' => (int) config('sitex.agent.default_autonomy_level', 1),
                'timezone' => $timezone ?? 'Asia/Ho_Chi_Minh'])->save();
            $c->refresh();
        }

        return $c;
    }
}
