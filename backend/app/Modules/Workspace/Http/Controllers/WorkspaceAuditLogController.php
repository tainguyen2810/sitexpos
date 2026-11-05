<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Modules\Audit\Domain\AuditLog;
use SiteXPost\Modules\Audit\Http\Resources\AuditLogResource;
use SiteXPost\Modules\Audit\Queries\AuditLogFilter;
use SiteXPost\Modules\Workspace\Domain\Workspace;

final class WorkspaceAuditLogController
{
    public function __invoke(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAudit', $workspace);

        // AuditLog is not globally scoped: filter by the resolved tenant explicitly.
        $query = AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->with(['actor', 'impersonator']);

        AuditLogFilter::apply($query, $request);

        return Paginated::response($query->orderByDesc('id'), AuditLogResource::class, $request);
    }
}
