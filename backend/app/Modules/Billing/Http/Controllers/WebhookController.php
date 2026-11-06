<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SiteXPost\Modules\Billing\Payments\Reconciliation;

/**
 * Public payment webhook. Authenticity = adapter signature check (no
 * session, no CSRF surface). Returns 200 for duplicates so providers stop
 * retrying; never echoes payload data.
 */
final class WebhookController
{
    public function __invoke(Request $request, string $provider, Reconciliation $reconciliation): JsonResponse
    {
        $result = $reconciliation->handleWebhook($provider, $request);

        return new JsonResponse(['data' => ['status' => $result['status'], 'results' => $result['results'] ?? []]]);
    }
}
