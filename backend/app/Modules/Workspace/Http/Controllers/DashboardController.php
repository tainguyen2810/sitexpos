<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Enums\SiteStatus;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Dashboard: only real data that exists now. Modules from later phases
 * report `available: false` instead of placeholder numbers.
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

        // Read-only use of the Sites module model (ARCHITECTURE.md §4.2).
        $sites = Site::query()->count();
        $connectedSites = Site::query()->whereIn('status', [SiteStatus::Connected, SiteStatus::Degraded])->count();

        return new JsonResponse(['data' => [
            'members_count' => $members,
            'sites_count' => $sites,
            'connected_sites_count' => $connectedSites,
            'pending_invitations_count' => $pendingInvitations,
            'modules' => [
                'billing' => ['available' => false, 'phase' => 3],
                'content' => ['available' => false, 'phase' => 6],
            ],
            'onboarding' => [
                ['key' => 'create_workspace', 'done' => true],
                ['key' => 'invite_team', 'done' => $members > 1 || $pendingInvitations > 0],
                ['key' => 'connect_site', 'done' => $connectedSites > 0],
            ],
        ]]);
    }
}
