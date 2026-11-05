<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Actions\AcceptInvitation;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;
use SiteXPost\Modules\Workspace\Http\Resources\WorkspaceResource;

final class PublicInvitationController
{
    /**
     * Minimal, non-sensitive preview so the invitee knows what they accept.
     */
    public function show(string $token, AcceptInvitation $action): JsonResponse
    {
        $invitation = $action->find($token);

        if ($invitation === null || $invitation->workspace === null || $invitation->workspace->status !== WorkspaceStatus::Active) {
            throw ApiException::notFound();
        }

        return new JsonResponse(['data' => [
            'workspace_name' => $invitation->workspace->name,
            'inviter_name' => $invitation->inviter?->name,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'status' => $invitation->status(),
            'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
        ]]);
    }

    public function accept(Request $request, string $token, AcceptInvitation $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $action->handle($token, $user);

        return new JsonResponse(['data' => [
            ...(new WorkspaceResource($workspace))->resolve($request),
        ]]);
    }
}
