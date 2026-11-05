<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Modules\Audit\Domain\AuditLog;
use SiteXPost\Modules\Audit\Http\Resources\AuditLogResource;
use SiteXPost\Modules\Audit\Queries\AuditLogFilter;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Platform\Enums\PlatformPermission;

final class AdminAuditLogController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = AuditLog::query()->with(['actor', 'impersonator']);

        if ($user->hasPlatformPermission(PlatformPermission::AuditView)) {
            // SUPPORT sees everything except billing; SUPER_ADMIN sees all.
            if (! $user->hasPlatformPermission(PlatformPermission::AuditViewBilling)) {
                $query->where('action', 'not like', 'billing.%')->where('action', 'not like', 'platform.billing.%');
            }
        } elseif ($user->hasPlatformPermission(PlatformPermission::AuditViewBilling)) {
            $query->where(fn ($q) => $q->where('action', 'like', 'billing.%')->orWhere('action', 'like', 'platform.billing.%'));
        } else {
            throw ApiException::forbidden();
        }

        $workspaceId = $request->query('workspace_id');
        if (is_string($workspaceId) && Str::isUlid($workspaceId)) {
            $query->where('workspace_id', $workspaceId);
        }

        AuditLogFilter::apply($query, $request);

        return Paginated::response($query->orderByDesc('id'), AuditLogResource::class, $request);
    }
}
