<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent;

use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Agent\Contracts\AgentActivity;
use SiteXPost\Modules\Agent\Queries\AgentActivityQuery;

/**
 * Phase 8 — Autonomous SEO Agent (AUTONOMOUS-SEO-AGENT.md, AGENT-POLICY-ENGINE.md).
 */
final class AgentServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AgentActivity::class, AgentActivityQuery::class);
    }
}
