<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Platform\Domain\ImpersonationSession;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the `{workspace}` URL segment and the
 * authenticated user's ACTIVE membership. Runs before SubstituteBindings so
 * that child bindings ({member}, {invitation}...) are resolved inside the
 * tenant scope. Non-members get 404 (existence is not disclosed).
 */
final class ResolveWorkspace
{
    public const IMPERSONATION_SESSION_KEY = 'sitex.impersonation_id';

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $user = $request->user();

        if ($route === null || ! $user instanceof User) {
            throw ApiException::notFound();
        }

        $param = $route->parameter('workspace');
        $workspaceId = $param instanceof Workspace ? (string) $param->getKey() : (string) $param;

        if (! Str::isUlid($workspaceId)) {
            throw ApiException::notFound();
        }

        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null || $workspace->status === WorkspaceStatus::PendingDeletion) {
            throw ApiException::notFound();
        }

        $impersonation = $this->activeImpersonation($request, $user, $workspace);

        if ($impersonation !== null) {
            // Read-only support access; role ADMIN gives read visibility, writes are blocked below.
            $this->context->set($workspace, WorkspaceRole::Admin, null, (string) $impersonation->getKey());
        } else {
            $membership = $this->context->bypass(fn () => WorkspaceMember::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('user_id', $user->getKey())
                ->where('status', MemberStatus::Active)
                ->first());

            if ($membership === null) {
                throw ApiException::notFound();
            }

            if ($workspace->status === WorkspaceStatus::Suspended) {
                throw new ApiException(403, 'workspace_suspended', __('api.workspace_suspended'));
            }

            $this->context->set($workspace, $membership->role, (string) $membership->getKey());
        }

        $route->setParameter('workspace', $workspace);

        try {
            if ($this->context->isImpersonating() && ! $request->isMethodSafe()) {
                throw new ApiException(403, 'impersonation_read_only', __('api.impersonation_read_only'));
            }

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    private function activeImpersonation(Request $request, User $user, Workspace $workspace): ?ImpersonationSession
    {
        if (! $request->hasSession() || ! $user->isPlatformStaff()) {
            return null;
        }

        $id = $request->session()->get(self::IMPERSONATION_SESSION_KEY);
        if (! is_string($id)) {
            return null;
        }

        $session = ImpersonationSession::query()->find($id);

        if ($session === null
            || $session->staff_user_id !== $user->getKey()
            || $session->workspace_id !== $workspace->getKey()
            || ! $session->isActive()) {
            return null;
        }

        return $session;
    }
}
