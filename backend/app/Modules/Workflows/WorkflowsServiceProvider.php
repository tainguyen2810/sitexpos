<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Workflows\Console\PruneWorkflows;
use SiteXPost\Modules\Workflows\Console\RecoverWorkflows;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\JobClock;
use SiteXPost\Modules\Workflows\Engine\WorkflowRegistry;
use SiteXPost\Modules\Workflows\Listeners\RecordFailedJob;
use SiteXPost\Modules\Workflows\Policies\WorkflowRunPolicy;

final class WorkflowsServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        WorkflowRun::class => WorkflowRunPolicy::class,
    ];

    public function register(): void
    {
        $this->app->singleton(WorkflowRegistry::class);
        $this->app->singleton(JobClock::class);
    }

    protected function bootModule(): void
    {
        Event::listen(JobFailed::class, RecordFailedJob::class);

        if ($this->app->runningInConsole()) {
            $this->commands([RecoverWorkflows::class, PruneWorkflows::class]);
        }
    }
}
