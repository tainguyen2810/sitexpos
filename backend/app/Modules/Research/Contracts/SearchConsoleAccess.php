<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Contracts;

use SiteXPost\Modules\Research\SearchConsole\SearchConsoleException;

/**
 * Phase 8 — the Google Search Console link of a site (Phase 4 table
 * `search_console_connections`) for the Analytics module. One connection
 * and one verified property per site; tokens never leave Research.
 * Tenant-scoped through the current TenantContext.
 */
interface SearchConsoleAccess
{
    public function configured(): bool;

    /**
     * @return array{connected: bool, status: string, property_url: string|null, google_email: string|null, last_synced_at: string|null, last_error_code: string|null}
     */
    public function status(string $siteId): array;

    /**
     * Encrypted, single-use OAuth state bound to user + workspace + site;
     * Google redirects back to the Analytics settings page.
     *
     * @throws SearchConsoleException not_configured
     */
    public function authorizationUrl(string $siteId, string $userId): string;

    /**
     * Properties the connected Google account can actually access.
     *
     * @return list<array{site_url: string, permission: string}>
     *
     * @throws SearchConsoleException
     */
    public function properties(string $siteId): array;

    /**
     * Map the site to one of the account's verified properties
     * (re-checked against Google, never trusted from the client).
     *
     * @throws SearchConsoleException not_connected | property_unknown
     */
    public function selectProperty(string $siteId, string $propertyUrl, ?string $userId): void;

    /**
     * Re-check that the mapped property is still accessible (permission
     * removed in Google → `property_revoked`, connection flagged).
     *
     * @throws SearchConsoleException
     */
    public function verifyProperty(string $siteId): string;

    /**
     * searchAnalytics.query on the site's mapped property.
     *
     * @param  array<string, mixed>  $body
     * @return list<array{keys: list<string>, clicks: int, impressions: int, ctr: float, position: float}>
     *
     * @throws SearchConsoleException
     */
    public function query(string $siteId, array $body): array;

    /** Record the outcome of a sync on the connection (health). */
    public function markSynced(string $siteId, ?string $errorCode): void;

    /** Revoke at Google (best effort) and delete the tokens. */
    public function disconnect(string $siteId, ?string $userId): bool;
}
