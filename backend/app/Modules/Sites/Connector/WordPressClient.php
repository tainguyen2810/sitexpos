<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Connector;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Security\SsrfViolation;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteActivityLog;
use SiteXPost\Modules\Sites\Domain\SiteCredential;

/**
 * Signed HTTP client for the plugin REST API (`/sitex-post/v1/*`).
 *
 * Every call: SsrfGuard check + IP pinning (CURLOPT_RESOLVE), no redirects,
 * short timeouts, response size limit, HMAC request signature and mandatory
 * verification of the plugin's response signature (proves the response comes
 * from the paired plugin and was not altered).
 */
class WordPressClient
{
    public function __construct(private readonly SsrfGuard $guard) {}

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     *
     * @throws ConnectorException
     */
    public function call(Site $site, string $method, string $route, array $query = [], ?array $json = null, ?SiteCredential $credential = null): array
    {
        $body = $json === null ? '' : (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->send($site, $method, $route, $query, $body, $json === null ? null : 'application/json', $credential);
    }

    /**
     * Raw binary upload (media). The bytes are covered by the body hash.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     *
     * @throws ConnectorException
     */
    public function upload(Site $site, string $route, array $query, string $bytes, string $mime): array
    {
        return $this->send($site, 'POST', $route, $query, $bytes, $mime, null);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     *
     * @throws ConnectorException
     */
    protected function send(Site $site, string $method, string $route, array $query, string $body, ?string $contentType, ?SiteCredential $credential): array
    {
        $operation = strtolower($method).' '.$route;
        $started = microtime(true);

        try {
            $credential ??= $site->activeCredential();
            if ($credential === null || ! $credential->acceptsRequests()) {
                throw new ConnectorException('not_connected', __('sites.errors.not_connected'));
            }

            $url = $this->endpoint($site, $route);
            $target = $this->guardUrl($url);

            $timestamp = time();
            $nonce = Signer::nonce();
            $canonical = Signer::canonical(Signer::DIRECTION_TO_WP, $method, $route, $query, $timestamp, $nonce, $body);

            $headers = [
                'Accept' => 'application/json',
                'User-Agent' => 'SiteXPostCloud/1.0 (+connector)',
                Signer::HEADER_KEY => $credential->key_id,
                Signer::HEADER_TIMESTAMP => (string) $timestamp,
                Signer::HEADER_NONCE => $nonce,
                Signer::HEADER_SIGNATURE => Signer::sign($canonical, $credential->secret),
            ];

            $options = ['allow_redirects' => false];
            if (filter_var($target['ip'], FILTER_VALIDATE_IP) !== false) {
                $options['curl'] = [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$target['ip']]];
            }

            $fullUrl = $query === [] ? $url : $url.(str_contains($url, '?') ? '&' : '?').Signer::canonicalQuery($query);

            $request = Http::withHeaders($headers)
                ->withOptions($options)
                ->connectTimeout((int) config('sitex.connector.connect_timeout', 5))
                ->timeout((int) config('sitex.connector.timeout', 20));

            if ($body !== '') {
                $request = $request->withBody($body, $contentType ?? 'application/octet-stream');
            }

            try {
                $response = $request->send($method, $fullUrl);
            } catch (ConnectionException) {
                throw new ConnectorException('unreachable', __('sites.errors.unreachable'), null, true);
            }

            $data = $this->interpret($response, $credential, $nonce);
            if ($credential->exists) {
                $credential->forceFill(['last_used_at' => now()])->saveQuietly();
            }
            $this->log($site, $operation, 'info', $response->status(), null, null, $started);

            return $data;
        } catch (ConnectorException $e) {
            $this->log($site, $operation, 'error', $e->httpStatus, $e->errorCode, $e->getMessage(), $started);
            throw $e;
        }
    }

    public function endpoint(Site $site, string $route): string
    {
        $base = $site->capabilities['rest_url'] ?? null;
        if (is_string($base) && $base !== '' && parse_url($base, PHP_URL_HOST) === $site->host()) {
            if (str_contains($base, 'rest_route=')) {
                return $base.ltrim($route, '/');
            }

            return rtrim($base, '/').$route;
        }

        return rtrim($site->url, '/').'/?rest_route='.$route;
    }

    /**
     * @return array{scheme:string,host:string,port:int,ip:string}
     */
    private function guardUrl(string $url): array
    {
        try {
            return $this->guard->assertSafe($url);
        } catch (SsrfViolation $e) {
            throw new ConnectorException('blocked_url', __('sites.errors.blocked_url', ['reason' => $e->getMessage()]));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function interpret(Response $response, SiteCredential $credential, string $nonce): array
    {
        $raw = $response->body();
        $status = $response->status();

        if (strlen($raw) > (int) config('sitex.connector.max_response_bytes', 5 * 1024 * 1024)) {
            throw new ConnectorException('response_too_large', __('sites.errors.response_too_large'), $status);
        }

        $decoded = json_decode($raw, true);

        if ($status >= 300) {
            $pluginCode = is_array($decoded) && is_string($decoded['code'] ?? null) ? $decoded['code'] : null;

            throw match (true) {
                $status >= 300 && $status < 400 => new ConnectorException('redirected', __('sites.errors.redirected'), $status),
                $status === 404 && ($pluginCode === null || $pluginCode === 'rest_no_route') => new ConnectorException('plugin_unavailable', __('sites.errors.plugin_unavailable'), $status),
                $status === 401 || $status === 403 => new ConnectorException(
                    $pluginCode === 'sitex_not_connected' ? 'plugin_disconnected' : 'unauthorized',
                    __($pluginCode === 'sitex_not_connected' ? 'sites.errors.plugin_disconnected' : 'sites.errors.unauthorized'),
                    $status,
                ),
                $status === 429 => new ConnectorException('rate_limited', __('sites.errors.site_rate_limited'), $status, true),
                $status >= 500 => new ConnectorException('site_error', __('sites.errors.site_error'), $status, true),
                default => new ConnectorException(
                    $pluginCode !== null && str_starts_with($pluginCode, 'sitex_') ? substr($pluginCode, 6) : 'request_rejected',
                    is_array($decoded) && is_string($decoded['message'] ?? null) ? mb_substr(strip_tags($decoded['message']), 0, 300) : __('sites.errors.request_rejected'),
                    $status,
                ),
            };
        }

        $timestamp = (int) $response->header(Signer::HEADER_TIMESTAMP);
        $signature = $response->header(Signer::HEADER_SIGNATURE);
        if ($signature === '' || abs(time() - $timestamp) > (int) config('sitex.connector.clock_skew', 300)
            || ! Signer::verify(Signer::responseCanonical($nonce, $timestamp, $raw), $credential->secret, $signature)) {
            throw new ConnectorException('invalid_response_signature', __('sites.errors.invalid_response_signature'), $status);
        }

        if (! is_array($decoded)) {
            throw new ConnectorException('invalid_response', __('sites.errors.invalid_response'), $status);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function log(Site $site, string $operation, string $level, ?int $status, ?string $code, ?string $message, float $started): void
    {
        $log = new SiteActivityLog;
        $log->forceFill([
            'site_id' => $site->id,
            'direction' => 'out',
            'operation' => mb_substr($operation, 0, 48),
            'level' => $level,
            'http_status' => $status,
            'error_code' => $code,
            'message' => $message !== null ? mb_substr($message, 0, 500) : null,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'request_id' => app()->bound('request') ? request()->attributes->get('request_id') : null,
        ])->save();
    }
}
