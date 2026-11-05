<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Platform\Http\Requests\ReasonRequest;
use SiteXPost\Modules\Platform\Http\Resources\AdminWorkspaceResource;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Domain\WorkspaceMember;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

final class AdminWorkspaceController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        // Platform listing spans all tenants (members_count): reviewed scope bypass.
        return $context->bypass(fn () => $this->list($request));
    }

    private function list(Request $request): JsonResponse
    {
        $query = Workspace::query()->with('owner')->withCount('members');

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like)
                ->orWhereHas('owner', fn ($o) => $o->where('email', 'like', $like)));
        }

        $status = $request->query('status');
        if (is_string($status) && WorkspaceStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        return Paginated::response($query->orderByDesc('created_at')->orderByDesc('id'), AdminWorkspaceResource::class, $request);
    }

    public function show(string $workspace, TenantContext $context): JsonResponse
    {
        $model = $this->find($workspace);

        // Platform read across tenants: reviewed bypass, membership metadata only.
        $context->bypass(fn () => $model->load('owner')->loadCount('members'));
        $members = $context->bypass(fn () => WorkspaceMember::query()
            ->where('workspace_id', $model->id)
            ->with('user')
            ->orderBy('joined_at')
            ->get()
            ->map(fn (WorkspaceMember $m): array => [
                'id' => $m->id,
                'user' => ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email],
                'role' => $m->role->value,
                'status' => $m->status->value,
                'joined_at' => $m->joined_at->toIso8601ZuluString(),
            ])->all());

        return new JsonResponse(['data' => [
            ...(new AdminWorkspaceResource($model))->resolve(),
            'members' => $members,
        ]]);
    }

    public function suspend(ReasonRequest $request, string $workspace): JsonResponse
    {
        $model = $this->find($workspace);
        if ($model->status !== WorkspaceStatus::Active) {
            throw ApiException::conflict('invalid_state_transition', __('api.invalid_state_transition'));
        }

        $model->forceFill(['status' => WorkspaceStatus::Suspended])->save();
        Audit::record('platform.workspace.suspended', $model, ['reason' => $request->string('reason')->toString()], workspaceId: $model->id, actorType: 'platform_staff');

        return new JsonResponse(['data' => (new AdminWorkspaceResource($model))->resolve()]);
    }

    public function unsuspend(ReasonRequest $request, string $workspace): JsonResponse
    {
        $model = $this->find($workspace);
        if ($model->status !== WorkspaceStatus::Suspended) {
            throw ApiException::conflict('invalid_state_transition', __('api.invalid_state_transition'));
        }

        $model->forceFill(['status' => WorkspaceStatus::Active])->save();
        Audit::record('platform.workspace.unsuspended', $model, ['reason' => $request->string('reason')->toString()], workspaceId: $model->id, actorType: 'platform_staff');

        return new JsonResponse(['data' => (new AdminWorkspaceResource($model))->resolve()]);
    }

    private function find(string $id): Workspace
    {
        if (! Str::isUlid($id)) {
            throw ApiException::notFound();
        }

        return Workspace::query()->find($id) ?? throw ApiException::notFound();
    }
}
