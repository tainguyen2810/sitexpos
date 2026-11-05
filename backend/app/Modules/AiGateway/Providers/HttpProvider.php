<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SiteXPost\Kernel\Logging\SecretRedactor;
use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Security\SsrfViolation;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;

/**
 * Shared transport: one request, explicit timeouts, no automatic retries, no
 * redirects, errors classified for the gateway.
 *
 * Credentials and base URL come from the bound provider row (forProvider):
 * the encrypted key a Super Admin saved, else .env; the row's base_url, else
 * config. A base URL from the database is untrusted input: it is checked by
 * SsrfGuard and the connection is pinned to the verified IP on every call.
 */
abstract class HttpProvider implements ProviderAdapter
{
    protected ?AiProvider $provider = null;

    public function forProvider(AiProvider $provider): static
    {
        $clone = clone $this;
        $clone->provider = $provider;

        return $clone;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    protected function apiKey(): string
    {
        if ($this->provider !== null) {
            return (string) $this->provider->apiKey();
        }

        return trim((string) $this->config('api_key'));
    }

    protected function config(string $key): mixed
    {
        return config('sitex.ai.providers.'.($this->provider->code ?? $this->code()).'.'.$key);
    }

    protected function baseUrl(): string
    {
        $custom = $this->customBaseUrl();

        return $custom ?? rtrim((string) $this->config('base_url'), '/');
    }

    private function customBaseUrl(): ?string
    {
        $url = trim((string) ($this->provider->base_url ?? ''));

        return $url !== '' ? rtrim($url, '/') : null;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    protected function postJson(string $url, array $headers, array $body, int $timeoutSeconds): array
    {
        return $this->send('POST', $url, $headers, $body, $timeoutSeconds);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    protected function getJson(string $url, array $headers, int $timeoutSeconds): array
    {
        return $this->send('GET', $url, $headers, null, $timeoutSeconds);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    private function send(string $method, string $url, array $headers, ?array $body, int $timeoutSeconds): array
    {
        if (! $this->isConfigured()) {
            throw ProviderException::notConfigured($this->provider->code ?? $this->code());
        }

        $options = ['allow_redirects' => false];
        if ($this->customBaseUrl() !== null) {
            try {
                $target = app(SsrfGuard::class)->assertSafe($url);
            } catch (SsrfViolation $e) {
                throw new ProviderException('blocked_url', 'Provider base URL rejected: '.$e->reason.'.');
            }
            if (filter_var($target['host'], FILTER_VALIDATE_IP) === false && $target['ip'] !== $target['host']) {
                $options['curl'] = [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$target['ip']]];
            }
        }

        try {
            $pending = Http::withHeaders($headers)
                ->acceptJson()
                ->connectTimeout(max(1, (int) config('sitex.ai.connect_timeout', 5)))
                ->timeout(max(1, $timeoutSeconds))
                ->withOptions($options);
            $response = $method === 'GET' ? $pending->get($url) : $pending->asJson()->post($url, $body ?? []);
        } catch (ConnectionException $e) {
            $error = $this->classifyConnectionError($e);
            if ($method === 'GET' && $error->billingUncertain) {
                // Model listing is free: a dropped GET is a plain network error.
                $error = new ProviderException('network_error', 'The provider connection failed or timed out.', retryable: true, countsAsFailure: true);
            }
            throw $error;
        }

        if (! $response->successful()) {
            throw ProviderException::fromStatus(
                $response->status(),
                SecretRedactor::redact($this->errorMessage($response)),
                $this->retryAfter($response),
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new ProviderException('invalid_response', 'Provider returned a non-JSON body.', httpStatus: $response->status(), billingUncertain: $method === 'POST');
        }

        return $json;
    }

    /**
     * Model IDs from a list endpoint shaped {data: [{id}]} (OpenAI style).
     *
     * @param  array<string, mixed>  $json
     * @return list<string>
     */
    protected static function idsFrom(array $json, string $listKey = 'data', string $idKey = 'id'): array
    {
        $ids = [];
        foreach ((array) ($json[$listKey] ?? []) as $item) {
            if (is_array($item) && isset($item[$idKey]) && is_string($item[$idKey])) {
                $ids[] = $item[$idKey];
            }
        }

        return $ids;
    }

    /**
     * cURL connect-phase failures (DNS, refused, connect timeout) never reached
     * the provider: safe to retry. Anything after the request was sent
     * (operation timeout, reset while reading) may have been billed.
     */
    private function classifyConnectionError(ConnectionException $e): ProviderException
    {
        $msg = $e->getMessage();
        // 56 + "CONNECT tunnel failed": an egress proxy refused the tunnel before anything was sent.
        $connectPhase = (bool) preg_match('/cURL error (6|7|35|60):|Connection timed out after|Could not resolve|Failed to connect|Connection refused|CONNECT tunnel failed/i', $msg);

        if ($connectPhase) {
            return new ProviderException('connect_failed', 'Could not connect to the provider.', retryable: true, countsAsFailure: true);
        }

        return new ProviderException('response_timeout', 'The provider did not answer in time; the call may have been billed.', retryable: true, billingUncertain: true, countsAsFailure: true);
    }

    private function errorMessage(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            $error = $json['error'] ?? null;
            if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                return $error['message'];
            }
            if (is_string($error)) {
                return $error;
            }
        }

        return 'HTTP '.$response->status();
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    protected static function int(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
