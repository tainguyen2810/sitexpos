<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SiteXPost\Kernel\Tenancy\TenantContext;

/**
 * Base class for queued jobs that touch tenant data. The workspace id is
 * captured from the current TenantContext at dispatch time (never from user
 * input) and the context is rebuilt by RestoreTenantContext before handle().
 */
abstract class TenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $workspaceId;

    public function __construct()
    {
        $this->workspaceId = app(TenantContext::class)->workspaceId();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RestoreTenantContext];
    }
}
