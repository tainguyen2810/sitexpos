<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\SessionManager;

final class SessionController
{
    public function __construct(private readonly SessionManager $sessions) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse([
            'data' => $this->sessions->list($user),
            'meta' => ['supported' => $this->sessions->supported()],
        ]);
    }

    public function destroy(Request $request, string $session): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->sessions->revoke($user, $session)) {
            throw ApiException::notFound();
        }

        Audit::record('auth.session_revoked', $user);

        return response()->noContent();
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password:web']]);

        /** @var User $user */
        $user = $request->user();
        $count = $this->sessions->revokeOthers($user);
        Audit::record('auth.other_sessions_revoked', $user, ['count' => $count]);

        return new JsonResponse(['data' => ['revoked' => $count]]);
    }
}
