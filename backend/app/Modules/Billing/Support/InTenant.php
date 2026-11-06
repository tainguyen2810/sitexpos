<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Support;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Runs billing code inside a workspace context (webhooks, scheduler and staff
 * actions arrive without one). Never bypasses the tenant scope: tenant rows
 * are always read and written through the workspace they belong to.
 */
final class InTenant
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(string $workspaceId, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        if ($context->has() && $context->workspaceId() === $workspaceId) {
            return $callback();
        }
        $workspace = Workspace::withTrashed()->find($workspaceId) ?? throw ApiException::notFound();

        return $context->runAs($workspace, null, $callback);
    }
}
