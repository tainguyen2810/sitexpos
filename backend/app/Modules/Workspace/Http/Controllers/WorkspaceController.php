<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Workspace\Actions\CreateWorkspace;
use SiteXPost\Modules\Workspace\Actions\RequestWorkspaceDeletion;
use SiteXPost\Modules\Workspace\Actions\TransferOwnership;
use SiteXPost\Modules\Workspace\Actions\UpdateWorkspace;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceRole;
use SiteXPost\Modules\Workspace\Http\Requests\DeleteWorkspaceRequest;
use SiteXPost\Modules\Workspace\Http\Requests\StoreWorkspaceRequest;
use SiteXPost\Modules\Workspace\Http\Requests\TransferOwnershipRequest;
use SiteXPost\Modules\Workspace\Http\Requests\UpdateWorkspaceRequest;
use SiteXPost\Modules\Workspace\Http\Resources\WorkspaceResource;
use SiteXPost\Modules\Workspace\Queries\UserWorkspaces;

final class WorkspaceController
{
    public function index(Request $request, UserWorkspaces $workspaces): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse(['data' => $workspaces->for($user)]);
    }

    public function store(StoreWorkspaceRequest $request, CreateWorkspace $action, TenantContext $context): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name:string, locale?:string|null, timezone?:string|null} $data */
        $data = $request->validated();
        $workspace = $action->handle($user, $data);

        $payload = $context->runAs($workspace, WorkspaceRole::Owner, fn (): array => (new WorkspaceResource($workspace))->resolve($request));

        return new JsonResponse(['data' => $payload], 201);
    }

    public function show(Request $request, Workspace $workspace): WorkspaceResource
    {
        Gate::authorize('view', $workspace);

        if ($request->user() instanceof User && ! $request->user()->isPlatformStaff()) {
            $request->user()->forceFill(['last_workspace_id' => $workspace->id])->saveQuietly();
        }

        return new WorkspaceResource($workspace);
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace, UpdateWorkspace $action): WorkspaceResource
    {
        Gate::authorize('update', $workspace);

        return new WorkspaceResource($action->handle($workspace, $request->validated()));
    }

    public function destroy(DeleteWorkspaceRequest $request, Workspace $workspace, RequestWorkspaceDeletion $action): Response
    {
        Gate::authorize('delete', $workspace);
        $action->handle($workspace);

        return response()->noContent();
    }

    public function transferOwnership(TransferOwnershipRequest $request, Workspace $workspace, TransferOwnership $action): Response
    {
        Gate::authorize('transferOwnership', $workspace);

        $member = $workspace->members()->find($request->string('member_id')->toString())
            ?? throw ApiException::notFound();

        $action->handle($workspace, $member);

        return response()->noContent();
    }
}
