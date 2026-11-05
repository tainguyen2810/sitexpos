<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\Middleware\ResolveWorkspace;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Platform\Domain\ImpersonationSession;
use SiteXPost\Modules\Platform\Http\Requests\StartImpersonationRequest;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

/**
 * Read-only support access to a workspace (Phase 1 only supports read-only).
 * Bound to the staff member's session; auto-expires (config sitex.impersonation.max_minutes).
 */
final class ImpersonationController
{
    public function store(StartImpersonationRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            throw ApiException::forbidden('session_required', __('api.forbidden'));
        }

        /** @var User $staff */
        $staff = $request->user();
        $workspace = Workspace::query()->find($request->string('workspace_id')->toString());

        if ($workspace === null || $workspace->status === WorkspaceStatus::PendingDeletion) {
            throw ApiException::notFound();
        }

        $this->endActive($request, $staff);

        $session = new ImpersonationSession;
        $session->forceFill([
            'staff_user_id' => $staff->id,
            'workspace_id' => $workspace->id,
            'reason' => $request->string('reason')->toString(),
            'read_only' => true,
            'started_at' => now(),
        ])->save();

        $request->session()->put(ResolveWorkspace::IMPERSONATION_SESSION_KEY, $session->id);

        Audit::record('platform.impersonation.started', $session, [
            'reason' => $session->reason,
            'read_only' => true,
        ], workspaceId: $workspace->id, actorType: 'platform_staff');

        return new JsonResponse(['data' => [
            'id' => $session->id,
            'workspace_id' => $workspace->id,
            'workspace_name' => $workspace->name,
            'read_only' => true,
            'expires_at' => $session->expiresAt()->toIso8601ZuluString(),
        ]], 201);
    }

    public function destroy(Request $request): Response
    {
        /** @var User $staff */
        $staff = $request->user();
        $this->endActive($request, $staff);

        return response()->noContent();
    }

    private function endActive(Request $request, User $staff): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $id = $request->session()->pull(ResolveWorkspace::IMPERSONATION_SESSION_KEY);
        if (! is_string($id)) {
            return;
        }

        $session = ImpersonationSession::query()->where('staff_user_id', $staff->id)->find($id);
        if ($session !== null && $session->ended_at === null) {
            $session->forceFill(['ended_at' => now()])->save();
            Audit::record('platform.impersonation.ended', $session, workspaceId: $session->workspace_id, actorType: 'platform_staff');
        }
    }
}
