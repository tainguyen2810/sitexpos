<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Connectors;

use SiteXPost\Modules\Research\Contracts\SearchConsoleAccess;

/**
 * Google Search Console adapter — delegates OAuth and API calls to the
 * Phase 4 integration through its public contract (no second OAuth flow,
 * no duplicate token table).
 */
final class SearchConsoleConnector implements AnalyticsConnector
{
    public function __construct(private readonly SearchConsoleAccess $gsc) {}

    public function provider(): string
    {
        return 'gsc';
    }

    public function available(): bool
    {
        return $this->gsc->configured();
    }

    public function connection(string $siteId): array
    {
        $s = $this->gsc->status($siteId);

        return ['connected' => $s['connected'], 'status' => $s['status'], 'property' => $s['property_url'], 'account' => $s['google_email'], 'last_error_code' => $s['last_error_code']];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array{keys: list<string>, clicks: int, impressions: int, ctr: float, position: float}>
     */
    public function query(string $siteId, array $body): array
    {
        return $this->gsc->query($siteId, $body);
    }

    public function verify(string $siteId): string
    {
        return $this->gsc->verifyProperty($siteId);
    }

    public function markSynced(string $siteId, ?string $errorCode): void
    {
        $this->gsc->markSynced($siteId, $errorCode);
    }
}
