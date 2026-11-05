<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Tenancy\Jobs;

use Closure;
use Illuminate\Support\Facades\Log;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

/**
 * Job middleware: re-establishes the tenant context for a TenantJob and
 * refuses to run jobs for workspaces that no longer exist or are not active.
 */
final class RestoreTenantContext
{
    public function handle(TenantJob $job, Closure $next): mixed
    {
        $workspace = Workspace::query()->find($job->workspaceId);

        if ($workspace === null || $workspace->status !== WorkspaceStatus::Active) {
            Log::warning('Tenant job skipped: workspace unavailable', [
                'job' => $job::class,
                'workspace_id' => $job->workspaceId,
            ]);
            $job->delete();

            return null;
        }

        return app(TenantContext::class)->runAs($workspace, null, static fn () => $next($job));
    }
}
