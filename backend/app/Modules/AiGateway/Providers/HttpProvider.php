<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SiteXPost\Kernel\Logging\SecretRedactor;

/**
 * Shared transport: one POST, explicit timeouts, no automatic retries, no
 * redirects, errors classified for the gateway. Base URLs come from config
 * only (never from the database or user input).
 */
abstract class HttpProvider implements ProviderAdapter
{
    public function isConfigured(): bool
    {
        return trim((string) $this->config('api_key')) !== '';
    }

    protected function config(string $key): mixed
    {
        return config("sitex.ai.providers.{$this->code()}.{$key}");
    }

    protected function baseUrl(): string
    {
        return rtrim((string) $this->config('base_url'), '/');
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
        if (! $this->isConfigured()) {
            throw ProviderException::notConfigured($this->code());
        }

        try {
            $response = Http::withHeaders($headers)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(max(1, (int) config('sitex.ai.connect_timeout', 5)))
                ->timeout(max(1, $timeoutSeconds))
                ->withOptions(['allow_redirects' => false])
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw $this->classifyConnectionError($e);
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
            throw new ProviderException('invalid_response', 'Provider returned a non-JSON body.', httpStatus: $response->status(), billingUncertain: true);
        }

        return $json;
    }

    /**
     * cURL connect-phase failures (DNS, refused, connect timeout) never reached
     * the provider: safe to retry. Anything after the request was sent
     * (operation timeout, reset while reading) may have been billed.
     */
    private function classifyConnectionError(ConnectionException $e): ProviderException
    {
        $msg = $e->getMessage();
        $connectPhase = (bool) preg_match('/cURL error (6|7|35|60):|Connection timed out after|Could not resolve|Failed to connect|Connection refused/i', $msg);

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
