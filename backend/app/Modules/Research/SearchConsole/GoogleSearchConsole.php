<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\SearchConsole;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SiteXPost\Modules\Research\Domain\SearchConsoleConnection;

/**
 * Google Search Console over OAuth 2.0 (scope webmasters.readonly).
 * Endpoints are Google's fixed hosts. Tokens are stored encrypted on
 * SearchConsoleConnection and never leave the server.
 */
class GoogleSearchConsole
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const API = 'https://www.googleapis.com/webmasters/v3';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /** Search Console API page size limit (searchAnalytics.query rowLimit). */
    public const MAX_ROWS = 25000;

    public function isConfigured(): bool
    {
        return (string) config('services.google.client_id') !== '' && (string) config('services.google.client_secret') !== '';
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => (string) config('services.google.client_id'),
            'redirect_uri' => (string) config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => 'openid email '.self::SCOPE,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * Phase 8: the granted scopes are validated — a user who unticks the
     * Search Console permission on Google's consent screen gets
     * `scope_missing` instead of a connection that fails later.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_in: int, email: string|null, scope: string|null}
     *
     * @throws SearchConsoleException
     */
    public function exchange(string $code): array
    {
        $data = $this->token([
            'code' => $code,
            'redirect_uri' => (string) config('services.google.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);
        if ($data['scope'] !== null && ! self::hasScope($data['scope'])) {
            $this->revokeToken($data['refresh_token'] ?? $data['access_token']);
            throw new SearchConsoleException('scope_missing', 'Google did not grant the Search Console read-only scope.');
        }

        $email = null;
        try {
            $info = Http::withToken($data['access_token'])->timeout(10)->get(self::USERINFO_URL);
            $email = $info->successful() ? (is_string($info->json('email')) ? $info->json('email') : null) : null;
        } catch (ConnectionException) {
        }

        return [...$data, 'email' => $email];
    }

    /**
     * @throws SearchConsoleException
     */
    public function accessToken(SearchConsoleConnection $connection): string
    {
        if ($connection->access_token_encrypted !== null && $connection->token_expires_at !== null && $connection->token_expires_at->isAfter(now()->addMinute())) {
            return (string) $connection->access_token_encrypted;
        }
        if ($connection->refresh_token_encrypted === null) {
            throw new SearchConsoleException('reauth_required', 'Search Console connection needs to be re-authorised.');
        }
        $data = $this->token(['refresh_token' => (string) $connection->refresh_token_encrypted, 'grant_type' => 'refresh_token']);
        $connection->forceFill([
            'access_token_encrypted' => $data['access_token'],
            'token_expires_at' => now()->addSeconds(max(60, $data['expires_in'])),
        ])->save();

        return $data['access_token'];
    }

    /**
     * @return list<array{site_url: string, permission: string}>
     *
     * @throws SearchConsoleException
     */
    public function sites(SearchConsoleConnection $connection): array
    {
        $res = $this->api($connection, 'GET', '/sites');
        $out = [];
        foreach ((array) ($res['siteEntry'] ?? []) as $entry) {
            if (is_array($entry) && is_string($entry['siteUrl'] ?? null)) {
                $out[] = ['site_url' => $entry['siteUrl'], 'permission' => (string) ($entry['permissionLevel'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * Top queries of the property (searchAnalytics.query, dimension=query).
     *
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>
     *
     * @throws SearchConsoleException
     */
    public function queries(SearchConsoleConnection $connection, string $property, string $start, string $end, int $rowLimit = 1000): array
    {
        $res = $this->api($connection, 'POST', '/sites/'.rawurlencode($property).'/searchAnalytics/query', [
            'startDate' => $start, 'endDate' => $end, 'dimensions' => ['query'], 'rowLimit' => max(1, min(5000, $rowLimit)), 'dataState' => 'final',
        ]);
        $rows = [];
        foreach ((array) ($res['rows'] ?? []) as $row) {
            if (! is_array($row) || ! is_string($row['keys'][0] ?? null)) {
                continue;
            }
            $rows[] = [
                'query' => $row['keys'][0],
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Generic searchAnalytics.query (Phase 8 ingestion). `$body` is built by
     * the caller (dimensions, startRow, searchType, dataState); rows keep
     * their `keys` in dimension order.
     *
     * @param  array<string, mixed>  $body
     * @return list<array{keys: list<string>, clicks: int, impressions: int, ctr: float, position: float}>
     *
     * @throws SearchConsoleException
     */
    public function searchAnalytics(SearchConsoleConnection $connection, string $property, array $body): array
    {
        $body['rowLimit'] = max(1, min(self::MAX_ROWS, (int) ($body['rowLimit'] ?? self::MAX_ROWS)));
        $res = $this->api($connection, 'POST', '/sites/'.rawurlencode($property).'/searchAnalytics/query', $body);
        $rows = [];
        foreach ((array) ($res['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $keys = array_values(array_map('strval', array_filter((array) ($row['keys'] ?? []), 'is_scalar')));
            $rows[] = [
                'keys' => $keys,
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'position' => (float) ($row['position'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Revoke the grant at Google (disconnect). Best effort: a token that is
     * already invalid counts as revoked.
     */
    public function revoke(SearchConsoleConnection $connection): bool
    {
        $token = $connection->refresh_token_encrypted ?? $connection->access_token_encrypted;

        return $token === null || $this->revokeToken((string) $token);
    }

    public static function hasScope(string $granted): bool
    {
        return in_array(self::SCOPE, preg_split('/\s+/', trim($granted)) ?: [], true);
    }

    private function revokeToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return true;
        }
        try {
            $res = Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
        } catch (ConnectionException) {
            return false;
        }

        return $res->successful() || $res->status() === 400;
    }

    /**
     * Pick the verified property matching the site: Domain property first,
     * then the URL-prefix property.
     *
     * @param  list<array{site_url: string, permission: string}>  $sites
     */
    public static function matchProperty(string $siteUrl, array $sites): ?string
    {
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($siteUrl, PHP_URL_HOST)));
        foreach ($sites as $s) {
            if ($s['permission'] !== 'siteUnverifiedUser' && strtolower($s['site_url']) === 'sc-domain:'.$host) {
                return $s['site_url'];
            }
        }
        foreach ($sites as $s) {
            $h = preg_replace('/^www\./', '', strtolower((string) parse_url($s['site_url'], PHP_URL_HOST)));
            if ($s['permission'] !== 'siteUnverifiedUser' && $h === $host) {
                return $s['site_url'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $params
     * @return array{access_token: string, refresh_token: string|null, expires_in: int, scope: string|null}
     *
     * @throws SearchConsoleException
     */
    private function token(array $params): array
    {
        if (! $this->isConfigured()) {
            throw new SearchConsoleException('not_configured', 'Google OAuth client is not configured.');
        }
        try {
            $res = Http::asForm()->timeout(15)->post(self::TOKEN_URL, $params + [
                'client_id' => (string) config('services.google.client_id'),
                'client_secret' => (string) config('services.google.client_secret'),
            ]);
        } catch (ConnectionException) {
            throw new SearchConsoleException('unreachable', 'Google OAuth unreachable.', true);
        }
        if (! $res->successful() || ! is_string($res->json('access_token'))) {
            $err = (string) ($res->json('error') ?? 'token_error');
            throw new SearchConsoleException($err === 'invalid_grant' ? 'reauth_required' : 'oauth_failed', 'Google OAuth error: '.mb_substr($err, 0, 60), $res->serverError());
        }

        return [
            'access_token' => (string) $res->json('access_token'),
            'refresh_token' => is_string($res->json('refresh_token')) ? (string) $res->json('refresh_token') : null,
            'expires_in' => (int) ($res->json('expires_in') ?? 3600),
            'scope' => is_string($res->json('scope')) ? (string) $res->json('scope') : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     *
     * @throws SearchConsoleException
     */
    private function api(SearchConsoleConnection $connection, string $method, string $path, ?array $json = null): array
    {
        $token = $this->accessToken($connection);
        try {
            $req = Http::withToken($token)->acceptJson()->timeout(20);
            $res = $method === 'GET' ? $req->get(self::API.$path) : $req->post(self::API.$path, $json ?? []);
        } catch (ConnectionException) {
            throw new SearchConsoleException('unreachable', 'Search Console API unreachable.', true);
        }
        if ($res->status() === 401) {
            throw new SearchConsoleException('reauth_required', 'Search Console token rejected.');
        }
        if ($res->status() === 403) {
            $reason = (string) ($res->json('error.errors.0.reason') ?? '');
            if (in_array($reason, ['quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded'], true)) {
                throw new SearchConsoleException('quota_exceeded', 'Search Console API quota exceeded.', true);
            }
            throw new SearchConsoleException('forbidden', 'No access to this Search Console property.');
        }
        if ($res->status() === 429) {
            throw new SearchConsoleException('quota_exceeded', 'Search Console API rate limit.', true);
        }
        if (! $res->successful()) {
            throw new SearchConsoleException('api_error', 'Search Console API error '.$res->status(), $res->serverError());
        }

        return (array) $res->json();
    }
}
