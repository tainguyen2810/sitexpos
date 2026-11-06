<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Http;

use Illuminate\Http\Request;
use SiteXPost\Kernel\Http\ApiException;

final class Idempotency
{
    /** Validated `Idempotency-Key` header (null when absent). */
    public static function key(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            return null;
        }
        if (! is_string($key) || preg_match('/^[A-Za-z0-9_\-:.]{8,120}$/', $key) !== 1) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['Idempotency-Key' => ['8–120 characters: letters, digits, _ - : .']]);
        }

        return $key;
    }
}
