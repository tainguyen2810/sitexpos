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
     * @return array{access_token: string, refresh_token: string|null, expires_in: int, email: string|null}
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
     * @return array{access_token: string, refresh_token: string|null, expires_in: int}
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
            throw new SearchConsoleException('forbidden', 'No access to this Search Console property.');
        }
        if (! $res->successful()) {
            throw new SearchConsoleException('api_error', 'Search Console API error '.$res->status(), $res->serverError() || $res->status() === 429);
        }

        return (array) $res->json();
    }
}
