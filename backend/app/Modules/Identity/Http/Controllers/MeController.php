<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SiteXPost\Kernel\Tenancy\Middleware\ResolveWorkspace;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Http\Requests\UpdateProfileRequest;
use SiteXPost\Modules\Identity\Http\Resources\UserResource;
use SiteXPost\Modules\Platform\Domain\ImpersonationSession;
use SiteXPost\Modules\Workspace\Queries\UserWorkspaces;

final class MeController
{
    public function show(Request $request, UserWorkspaces $workspaces): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['data' => $this->payload($request, $user, $workspaces)]);
    }

    public function update(UpdateProfileRequest $request, UserWorkspaces $workspaces): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->fill($request->validated());
        $changes = $user->getDirty();
        $user->save();

        if ($changes !== []) {
            Audit::record('user.profile_updated', $user, ['fields' => array_keys($changes)]);
        }

        return new JsonResponse(['data' => $this->payload($request, $user, $workspaces)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request, User $user, UserWorkspaces $workspaces): array
    {
        $impersonation = null;
        if ($request->hasSession() && $user->isPlatformStaff()) {
            $id = $request->session()->get(ResolveWorkspace::IMPERSONATION_SESSION_KEY);
            $session = is_string($id) ? ImpersonationSession::query()->with('workspace')->find($id) : null;
            if ($session !== null && $session->isUsableBy($user)) {
                $impersonation = [
                    'id' => $session->id,
                    'workspace_id' => $session->workspace_id,
                    'workspace_name' => $session->workspace?->name,
                    'read_only' => true,
                    'expires_at' => $session->expiresAt()->toIso8601ZuluString(),
                ];
            }
        }

        return [
            ...(new UserResource($user))->resolve($request),
            'workspaces' => $workspaces->for($user),
            'impersonation' => $impersonation,
        ];
    }
}
