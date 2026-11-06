<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Jobs;

use Illuminate\Support\Str;
use SiteXPost\Kernel\Tenancy\Jobs\TenantJob;
use SiteXPost\Modules\Workflows\Engine\WorkflowEngine;

/**
 * Thin queue carrier: only the run id travels through Redis; all state is
 * in the database. Duplicate deliveries are harmless (CAS claim).
 */
final class ProcessWorkflow extends TenantJob
{
    /** Re-delivery after a crash is handled by the engine lease + recovery. */
    public int $tries = 3;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public string $runId)
    {
        parent::__construct();
    }

    public function handle(WorkflowEngine $engine): void
    {
        $engine->execute($this->runId, (string) Str::uuid());
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['workflow:'.$this->runId, 'ws:'.$this->workspaceId];
    }
}
