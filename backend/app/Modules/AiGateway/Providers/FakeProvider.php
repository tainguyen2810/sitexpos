<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * Deterministic offline provider for development, CI and E2E tests. Never
 * enabled in production (config sitex.ai.fake_provider). Behaviour can be
 * steered from the prompt so failure paths are testable end to end:
 *   [fail:rate_limited] [fail:unavailable] [fail:timeout] [fail:auth]
 *   [sleep:3]  (seconds, max 30 — used by the worker-restart E2E)
 *   [fail-once:unavailable:<key>] fails only the first call for <key>
 */
final class FakeProvider implements ProviderAdapter
{
    public function code(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return (bool) config('sitex.ai.fake_provider');
    }

    public function generate(ProviderRequest $request): ProviderResponse
    {
        if (! $this->isConfigured()) {
            throw ProviderException::notConfigured('fake');
        }

        if (preg_match('/\[sleep:(\d{1,2})\]/', $request->prompt, $m) === 1) {
            Sleep::for(min(30, (int) $m[1]))->seconds();
        }

        if (preg_match('/\[fail-once:([a-z_]+):([A-Za-z0-9_-]+)\]/', $request->prompt, $m) === 1
            && Cache::add('ai:fake:fail-once:'.$m[2], 1, 3600)) {
            throw $this->failure($m[1]);
        }

        if (preg_match('/\[fail:([a-z_]+)\]/', $request->prompt, $m) === 1) {
            throw $this->failure($m[1]);
        }

        $inputTokens = (int) ceil(mb_strlen(($request->system ?? '').$request->prompt) / 4);

        if ($request->kind === 'image') {
            return new ProviderResponse('', new Usage(inputTokens: $inputTokens, images: $request->imageCount), 'stop', 'fake_'.bin2hex(random_bytes(6)), 200,
                array_fill(0, $request->imageCount, base64_encode('fake-image')));
        }

        $summary = mb_substr(trim(preg_replace('/\[[a-z\-]+:[^\]]*\]/', '', $request->prompt) ?? ''), 0, 160);
        $text = $request->schema !== null
            ? (string) json_encode($this->sample($request->schema, $summary), JSON_UNESCAPED_UNICODE)
            : 'Phản hồi thử nghiệm (fake provider): '.$summary;

        $outputTokens = min($request->maxOutputTokens, max(1, (int) ceil(mb_strlen($text) / 4)));

        return new ProviderResponse($text, new Usage(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            searches: $request->webSearch ? 1 : 0,
        ), 'stop', 'fake_'.bin2hex(random_bytes(6)));
    }

    private function failure(string $kind): ProviderException
    {
        return match ($kind) {
            'rate_limited' => ProviderException::fromStatus(429, 'Fake rate limit', 0),
            'unavailable' => ProviderException::fromStatus(503, 'Fake outage'),
            'auth' => ProviderException::fromStatus(401, 'Fake auth failure'),
            'bad_request' => ProviderException::fromStatus(400, 'Fake bad request'),
            'timeout' => new ProviderException('response_timeout', 'Fake response timeout', retryable: true, billingUncertain: true, countsAsFailure: true),
            'refused' => new ProviderException('refused', 'Fake refusal'),
            default => new ProviderException('provider_error', 'Fake failure'),
        };
    }

    /**
     * Minimal instance that satisfies a JSON schema (objects, arrays, scalars, enums).
     *
     * @param  array<string, mixed>  $schema
     */
    private function sample(array $schema, string $text): mixed
    {
        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        $type = $schema['type'] ?? 'object';
        if (is_array($type)) {
            $type = $type[0] ?? 'string';
        }

        return match ($type) {
            'object' => (function () use ($schema, $text): object {
                $out = [];
                foreach ((array) ($schema['properties'] ?? []) as $name => $prop) {
                    $out[(string) $name] = $this->sample(is_array($prop) ? $prop : [], $text);
                }

                return (object) $out;
            })(),
            'array' => [$this->sample(is_array($schema['items'] ?? null) ? $schema['items'] : ['type' => 'string'], $text)],
            'integer' => 1,
            'number' => 1.0,
            'boolean' => true,
            'null' => null,
            default => $text === '' ? 'ok' : $text,
        };
    }
}
