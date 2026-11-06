<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Agent\Console\RecoverAgent;
use SiteXPost\Modules\Agent\Console\RunAgentSchedules;
use SiteXPost\Modules\Agent\Contracts\AgentActivity;
use SiteXPost\Modules\Agent\Domain\AgentActionLog;
use SiteXPost\Modules\Agent\Domain\AgentConfig;
use SiteXPost\Modules\Agent\Domain\AgentMemory;
use SiteXPost\Modules\Agent\Domain\AgentRun;
use SiteXPost\Modules\Agent\Domain\AgentTask;
use SiteXPost\Modules\Agent\Domain\AutomationSchedule;
use SiteXPost\Modules\Agent\Policies\AgentAccessPolicy;
use SiteXPost\Modules\Agent\Queries\AgentActivityQuery;

/**
 * Phase 8 — Autonomous SEO Agent (AUTONOMOUS-SEO-AGENT.md, AGENT-POLICY-ENGINE.md).
 */
final class AgentServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        AgentConfig::class => AgentAccessPolicy::class,
        AgentRun::class => AgentAccessPolicy::class,
        AgentTask::class => AgentAccessPolicy::class,
        AgentActionLog::class => AgentAccessPolicy::class,
        AgentMemory::class => AgentAccessPolicy::class,
        AutomationSchedule::class => AgentAccessPolicy::class,
    ];

    public function register(): void
    {
        $this->app->bind(AgentActivity::class, AgentActivityQuery::class);
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RunAgentSchedules::class, RecoverAgent::class]);
        }
        RateLimiter::for('agent-run', static function (Request $request): Limit {
            $workspace = $request->route('workspace');
            $wsKey = is_object($workspace) && isset($workspace->id) ? (string) $workspace->id : (string) $workspace;

            return Limit::perMinute(6)->by('agent-run:'.($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.$wsKey);
        });
    }
}
