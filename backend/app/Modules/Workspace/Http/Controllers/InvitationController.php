<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Actions\InviteMember;
use SiteXPost\Modules\Workspace\Actions\RevokeInvitation;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;
use SiteXPost\Modules\Workspace\Http\Requests\StoreInvitationRequest;
use SiteXPost\Modules\Workspace\Http\Resources\InvitationResource;

final class InvitationController
{
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WorkspaceInvitation::class);

        $invitations = $workspace->invitations()
            ->with('inviter')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->get();

        return InvitationResource::collection($invitations);
    }

    public function store(StoreInvitationRequest $request, Workspace $workspace, InviteMember $action): JsonResponse
    {
        $role = WorkspaceRole::from($request->string('role')->toString());
        Gate::authorize('create', [WorkspaceInvitation::class, $role]);

        /** @var User $user */
        $user = $request->user();
        $invitation = $action->handle($workspace, $user, $request->string('email')->toString(), $role);

        return (new InvitationResource($invitation->load('inviter')))->response()->setStatusCode(201);
    }

    public function destroy(Workspace $workspace, WorkspaceInvitation $invitation, RevokeInvitation $action): Response
    {
        Gate::authorize('delete', $invitation);
        $action->handle($invitation);

        return response()->noContent();
    }
}
