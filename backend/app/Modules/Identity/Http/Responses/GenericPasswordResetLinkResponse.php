<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Same response whether or not the email exists — prevents account enumeration.
 */
final class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(public string $status = '') {}

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(['data' => ['message' => __('api.password_reset_link_sent')]], 200);
    }
}
