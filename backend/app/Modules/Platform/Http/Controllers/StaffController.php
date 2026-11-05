<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Platform\Http\Requests\StaffRequest;
use SiteXPost\Modules\Platform\Http\Resources\AdminUserResource;

/**
 * SUPER_ADMIN only (enforced by route middleware).
 */
final class StaffController
{
    public function index(): JsonResponse
    {
        $staff = User::query()->whereNotNull('platform_role')->orderBy('name')->get();

        return new JsonResponse(['data' => AdminUserResource::collection($staff)->resolve()]);
    }

    /**
     * Grant a platform role to an existing, verified user account.
     */
    public function store(StaffRequest $request): JsonResponse
    {
        $user = User::query()->where('email', mb_strtolower($request->string('email')->toString()))->first()
            ?? throw ApiException::notFound();

        if (! $user->hasVerifiedEmail()) {
            throw ApiException::conflict('email_not_verified', __('api.email_not_verified'));
        }

        $role = PlatformRole::from((string) $request->input('platform_role'));

        return $this->assign($request, $user, $role, 201);
    }

    public function update(StaffRequest $request, string $user): JsonResponse
    {
        if (! Str::isUlid($user)) {
            throw ApiException::notFound();
        }

        $model = User::query()->find($user) ?? throw ApiException::notFound();
        $role = $request->input('platform_role') === null ? null : PlatformRole::from((string) $request->input('platform_role'));

        return $this->assign($request, $model, $role, 200);
    }

    private function assign(Request $request, User $user, ?PlatformRole $role, int $status): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($user->id === $actor->id) {
            throw ApiException::conflict('cannot_change_own_platform_role', __('api.cannot_change_own_platform_role'));
        }

        $from = $user->platform_role;

        if ($from === PlatformRole::SuperAdmin && $role !== PlatformRole::SuperAdmin
            && User::query()->where('platform_role', PlatformRole::SuperAdmin)->count() <= 1) {
            throw ApiException::conflict('last_super_admin', __('api.last_super_admin'));
        }

        $user->forceFill(['platform_role' => $role])->save();

        Audit::record('platform.staff.role_changed', $user, [
            'from' => $from?->value,
            'to' => $role?->value,
        ], actorType: 'platform_staff');

        return new JsonResponse(['data' => (new AdminUserResource($user))->resolve()], $status);
    }
}
