<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\UserStatus;
use SiteXPost\Modules\Identity\SessionManager;
use SiteXPost\Modules\Platform\Enums\PlatformPermission;
use SiteXPost\Modules\Platform\Http\Requests\ReasonRequest;
use SiteXPost\Modules\Platform\Http\Resources\AdminUserResource;
use SiteXPost\Modules\Workspace\Queries\UserWorkspaces;

final class AdminUserController
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
            $query->where(fn ($u) => $u->where('email', 'like', $like)->orWhere('name', 'like', $like));
        }

        $status = $request->query('status');
        if (is_string($status) && UserStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        if ($request->boolean('staff')) {
            $query->whereNotNull('platform_role');
        }

        return Paginated::response($query->orderByDesc('created_at')->orderByDesc('id'), AdminUserResource::class, $request);
    }

    public function show(string $user, UserWorkspaces $workspaces): JsonResponse
    {
        $model = $this->find($user);

        return new JsonResponse(['data' => [
            ...(new AdminUserResource($model))->resolve(),
            'workspaces' => $workspaces->for($model),
        ]]);
    }

    public function suspend(ReasonRequest $request, string $user, SessionManager $sessions): JsonResponse
    {
        $model = $this->find($user);
        /** @var User $actor */
        $actor = $request->user();

        if ($model->id === $actor->id) {
            throw ApiException::conflict('cannot_suspend_self', __('api.cannot_suspend_self'));
        }

        if ($model->isPlatformStaff() && ! $actor->hasPlatformPermission(PlatformPermission::StaffManage)) {
            throw ApiException::forbidden();
        }

        if ($model->status === UserStatus::Suspended) {
            throw ApiException::conflict('invalid_state_transition', __('api.invalid_state_transition'));
        }

        $model->forceFill(['status' => UserStatus::Suspended])->save();
        $sessions->revokeAll($model);
        Audit::record('platform.user.suspended', $model, ['reason' => $request->string('reason')->toString()], actorType: 'platform_staff');

        return new JsonResponse(['data' => (new AdminUserResource($model))->resolve()]);
    }

    public function unsuspend(ReasonRequest $request, string $user): JsonResponse
    {
        $model = $this->find($user);

        if ($model->status !== UserStatus::Suspended) {
            throw ApiException::conflict('invalid_state_transition', __('api.invalid_state_transition'));
        }

        $model->forceFill(['status' => UserStatus::Active])->save();
        Audit::record('platform.user.unsuspended', $model, ['reason' => $request->string('reason')->toString()], actorType: 'platform_staff');

        return new JsonResponse(['data' => (new AdminUserResource($model))->resolve()]);
    }

    private function find(string $id): User
    {
        if (! Str::isUlid($id)) {
            throw ApiException::notFound();
        }

        return User::query()->find($id) ?? throw ApiException::notFound();
    }
}
