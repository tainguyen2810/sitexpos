<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\UserStatus;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

/**
 * Real aggregates only; no placeholder metrics.
 */
final class AdminDashboardController
{
    public function __invoke(TenantContext $context): JsonResponse
    {
        $since7 = now()->subDays(7);
        $since30 = now()->subDays(30);

        return new JsonResponse(['data' => [
            'users' => [
                'total' => User::query()->count(),
                'verified' => User::query()->whereNotNull('email_verified_at')->count(),
                'suspended' => User::query()->where('status', UserStatus::Suspended)->count(),
                'new_7d' => User::query()->where('created_at', '>=', $since7)->count(),
                'new_30d' => User::query()->where('created_at', '>=', $since30)->count(),
            ],
            'workspaces' => [
                'total' => Workspace::query()->count(),
                'active' => Workspace::query()->where('status', WorkspaceStatus::Active)->count(),
                'suspended' => Workspace::query()->where('status', WorkspaceStatus::Suspended)->count(),
                'new_7d' => Workspace::query()->where('created_at', '>=', $since7)->count(),
                'new_30d' => Workspace::query()->where('created_at', '>=', $since30)->count(),
            ],
            'invitations_pending' => $context->bypass(fn () => WorkspaceInvitation::query()
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count()),
            'generated_at' => now()->toIso8601ZuluString(),
        ]]);
    }
}
