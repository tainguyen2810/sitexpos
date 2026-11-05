<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Tenancy;

use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\Permission;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;

/**
 * Holds the tenant (workspace) for the current request or job.
 *
 * The context is ONLY ever populated server-side: by the ResolveWorkspace
 * middleware (from the URL + verified membership), by RestoreTenantContext
 * for queued jobs, or explicitly by trusted system code via runAs().
 * Never populate it from request body/query input.
 */
final class TenantContext
{
    private ?Workspace $workspace = null;

    private ?WorkspaceRole $role = null;

    private ?string $memberId = null;

    private ?string $impersonationId = null;

    private int $bypassDepth = 0;

    public function set(
        Workspace $workspace,
        ?WorkspaceRole $role,
        ?string $memberId = null,
        ?string $impersonationId = null,
    ): void {
        $this->workspace = $workspace;
        $this->role = $role;
        $this->memberId = $memberId;
        $this->impersonationId = $impersonationId;
    }

    public function clear(): void
    {
        $this->workspace = null;
        $this->role = null;
        $this->memberId = null;
        $this->impersonationId = null;
    }

    public function has(): bool
    {
        return $this->workspace !== null;
    }

    public function workspace(): Workspace
    {
        return $this->workspace ?? throw new MissingTenantContextException('TenantContext');
    }

    public function workspaceId(): string
    {
        return (string) $this->workspace()->getKey();
    }

    public function role(): ?WorkspaceRole
    {
        return $this->role;
    }

    public function memberId(): ?string
    {
        return $this->memberId;
    }

    public function impersonationId(): ?string
    {
        return $this->impersonationId;
    }

    public function isImpersonating(): bool
    {
        return $this->impersonationId !== null;
    }

    /**
     * Impersonation sessions are read-only: only read permissions are granted.
     */
    public function can(Permission $permission): bool
    {
        if ($this->role === null || ! $this->has()) {
            return false;
        }

        if ($this->isImpersonating() && ! $permission->isReadOnly()) {
            return false;
        }

        return $this->role->allows($permission);
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_map(
            static fn (Permission $p): string => $p->value,
            array_filter(Permission::cases(), fn (Permission $p): bool => $this->can($p)),
        ));
    }

    /**
     * Run trusted system code inside a specific workspace (e.g. queued jobs,
     * invitation acceptance). The previous context is restored afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Workspace $workspace, ?WorkspaceRole $role, callable $callback): mixed
    {
        $previous = [$this->workspace, $this->role, $this->memberId, $this->impersonationId];
        $this->set($workspace, $role);

        try {
            return $callback();
        } finally {
            [$this->workspace, $this->role, $this->memberId, $this->impersonationId] = $previous;
        }
    }

    /**
     * Disable the fail-closed workspace scope for trusted cross-tenant code
     * (platform admin, membership lookup). Every call site must be reviewed.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function bypass(callable $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }
}
