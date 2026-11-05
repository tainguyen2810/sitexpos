<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\MemberStatus;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

final class AcceptInvitation
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Cross-tenant lookup by secret token: the only legitimate way for a
     * non-member to reach a workspace's data.
     */
    public function find(string $token): ?WorkspaceInvitation
    {
        if (strlen($token) !== 64) {
            return null;
        }

        return $this->context->bypass(fn () => WorkspaceInvitation::query()
            ->with(['workspace', 'inviter'])
            ->where('token_hash', WorkspaceInvitation::hashToken($token))
            ->first());
    }

    public function handle(string $token, User $user): Workspace
    {
        $invitation = $this->find($token) ?? throw ApiException::notFound();
        $workspace = $invitation->workspace;

        if ($workspace === null || $workspace->status !== WorkspaceStatus::Active) {
            throw ApiException::notFound();
        }

        if (! $invitation->isPending()) {
            throw ApiException::conflict('invitation_'.$invitation->status(), __('api.invitation_not_pending'));
        }

        if (! hash_equals($invitation->email, mb_strtolower($user->email))) {
            throw ApiException::forbidden('invitation_email_mismatch', __('api.invitation_email_mismatch'));
        }

        return $this->context->runAs($workspace, null, function () use ($invitation, $workspace, $user): Workspace {
            DB::transaction(function () use ($invitation, $workspace, $user): void {
                $exists = $workspace->members()->where('user_id', $user->id)->exists();

                if (! $exists) {
                    (new WorkspaceMember)->forceFill([
                        'user_id' => $user->id,
                        'role' => $invitation->role,
                        'status' => MemberStatus::Active,
                        'invited_by' => $invitation->invited_by,
                        'joined_at' => now(),
                    ])->save();
                }

                $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();

                Audit::record('workspace.invitation.accepted', $invitation, [
                    'user_id' => $user->id,
                    'role' => $invitation->role->value,
                ], actorId: $user->id);
            });

            $user->forceFill(['last_workspace_id' => $workspace->id])->saveQuietly();

            return $workspace;
        });
    }
}
