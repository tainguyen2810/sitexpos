<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Contracts\Entitlements;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;
use SiteXPost\Modules\Workspace\Jobs\SendWorkspaceInvitation;

final class InviteMember
{
    public function handle(Workspace $workspace, User $inviter, string $email, WorkspaceRole $role): WorkspaceInvitation
    {
        $email = mb_strtolower(trim($email));

        $alreadyMember = $workspace->members()
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->exists();

        if ($alreadyMember) {
            throw ApiException::conflict('already_member', __('api.already_member'));
        }

        // Phase 7: plan member limit — active members + pending invitations.
        $seats = $workspace->members()->count() + $workspace->invitations()->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('email', '!=', $email)->where('expires_at', '>', now())->count();
        app(Entitlements::class)->assertWithin($workspace->id, 'members', $seats);

        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($workspace, $inviter, $email, $role, $token): WorkspaceInvitation {
            // A new invitation supersedes any pending one for the same email.
            $workspace->invitations()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $invitation = new WorkspaceInvitation;
            $invitation->forceFill([
                'email' => $email,
                'role' => $role,
                'token_hash' => WorkspaceInvitation::hashToken($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays((int) config('sitex.invitations.expire_days', 7)),
            ])->save();

            Audit::record('workspace.member.invited', $invitation, ['email' => $email, 'role' => $role->value]);

            return $invitation;
        });

        SendWorkspaceInvitation::dispatch($invitation->id, $token)->afterCommit();

        return $invitation;
    }
}
