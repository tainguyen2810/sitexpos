<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Modules\Workspace\Actions\ChangeMemberRole;
use SiteXPost\Modules\Workspace\Actions\RemoveMember;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;
use SiteXPost\Modules\Workspace\Http\Requests\UpdateMemberRequest;
use SiteXPost\Modules\Workspace\Http\Resources\MemberResource;

final class MemberController
{
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('viewMembers', $workspace);

        $members = $workspace->members()
            ->with('user')
            ->orderByRaw("CASE role WHEN 'OWNER' THEN 0 WHEN 'ADMIN' THEN 1 WHEN 'EDITOR' THEN 2 ELSE 3 END")
            ->orderBy('joined_at')
            ->get();

        return MemberResource::collection($members);
    }

    public function update(UpdateMemberRequest $request, Workspace $workspace, WorkspaceMember $member, ChangeMemberRole $action): MemberResource
    {
        $role = WorkspaceRole::from($request->string('role')->toString());
        Gate::authorize('update', [$member, $role]);

        return new MemberResource($action->handle($member, $role)->load('user'));
    }

    public function destroy(Request $request, Workspace $workspace, WorkspaceMember $member, RemoveMember $action): Response
    {
        Gate::authorize('delete', $member);
        $action->handle($member, $member->user_id === $request->user()?->getAuthIdentifier());

        return response()->noContent();
    }
}
