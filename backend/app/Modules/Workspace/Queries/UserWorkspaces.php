<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Queries;

use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

/**
 * Lists the workspaces a user belongs to. Cross-tenant by nature (it spans
 * the user's own memberships only), hence the reviewed scope bypass.
 */
final class UserWorkspaces
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return list<array{id:string,name:string,slug:string,role:string,status:string,is_demo:bool}>
     */
    public function for(User $user): array
    {
        return $this->context->bypass(fn (): array => WorkspaceMember::query()
            ->where('user_id', $user->id)
            ->where('status', MemberStatus::Active)
            ->whereHas('workspace', fn ($q) => $q->where('status', '!=', WorkspaceStatus::PendingDeletion->value))
            ->with('workspace')
            ->orderBy('created_at')
            ->get()
            ->map(fn (WorkspaceMember $m): array => [
                'id' => $m->workspace->id,
                'name' => $m->workspace->name,
                'slug' => $m->workspace->slug,
                'role' => $m->role->value,
                'status' => $m->workspace->status->value,
                'is_demo' => $m->workspace->is_demo,
            ])
            ->values()
            ->all());
    }
}
