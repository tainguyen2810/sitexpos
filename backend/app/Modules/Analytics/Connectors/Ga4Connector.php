<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Connectors;

/**
 * Google Analytics 4 — extension point only (not implemented in Phase 8).
 * A real adapter needs its own OAuth scope (analytics.readonly), property
 * mapping and separate metric tables (sessions/engagement are not GSC
 * clicks). Reported as unavailable so the UI never shows GA4 data.
 */
final class Ga4Connector implements AnalyticsConnector
{
    public function provider(): string
    {
        return 'ga4';
    }

    public function available(): bool
    {
        return false;
    }

    public function connection(string $siteId): array
    {
        return ['connected' => false, 'status' => 'not_available', 'property' => null, 'account' => null, 'last_error_code' => null];
    }
}
