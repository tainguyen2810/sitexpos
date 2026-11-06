<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Connectors;

/**
 * Analytics Connector layer (ANALYTICS-ARCHITECTURE.md §2). One adapter per
 * provider; ingestion only depends on this interface. Metric definitions
 * differ between providers (GSC clicks ≠ GA4 sessions) and are never merged
 * into the same columns.
 */
interface AnalyticsConnector
{
    public function provider(): string;

    /** Is the provider usable at all on this installation (OAuth client configured, adapter implemented)? */
    public function available(): bool;

    /**
     * @return array{connected: bool, status: string, property: string|null, account: string|null, last_error_code: string|null}
     */
    public function connection(string $siteId): array;
}
