<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Phase 1 dashboard: only real data that exists now. Modules from later
 * phases report `available: false` instead of placeholder numbers.
 */
final class DashboardController
{
    public function __invoke(Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $members = $workspace->members()->count();
        $pendingInvitations = $workspace->invitations()
            ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->count();

        return new JsonResponse(['data' => [
            'members_count' => $members,
            'pending_invitations_count' => $pendingInvitations,
            'modules' => [
                'sites' => ['available' => false, 'phase' => 2],
                'billing' => ['available' => false, 'phase' => 3],
                'content' => ['available' => false, 'phase' => 6],
            ],
            'onboarding' => [
                ['key' => 'create_workspace', 'done' => true],
                ['key' => 'invite_team', 'done' => $members > 1 || $pendingInvitations > 0],
                ['key' => 'connect_site', 'done' => false, 'available' => false],
            ],
        ]]);
    }
}
