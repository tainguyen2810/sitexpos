<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SiteXPost\Modules\AiGateway\Providers\AnthropicProvider;
use SiteXPost\Modules\AiGateway\Providers\GeminiProvider;
use SiteXPost\Modules\AiGateway\Providers\OpenAiProvider;
use SiteXPost\Modules\AiGateway\Providers\ProviderException;
use SiteXPost\Modules\AiGateway\Providers\ProviderRequest;

/**
 * Request/response shapes follow the official docs verified on 05/11/2026
 * (see docs/PHASE3-REPORT.md). No real network call is made.
 */
beforeEach(function () {
    config([
        'sitex.ai.providers.openai.api_key' => 'sk-test-openai-000000000000',
        'sitex.ai.providers.gemini.api_key' => 'AIzaTestGeminiKey000000000000',
        'sitex.ai.providers.anthropic.api_key' => 'sk-ant-test-000000000000',
    ]);
    Http::preventStrayRequests();
});

function req(array $over = []): ProviderRequest
{
    return new ProviderRequest(...[
        'model' => 'm', 'prompt' => 'Hello', 'system' => 'Be brief', 'schema' => null, 'schemaName' => 'result',
        'maxOutputTokens' => 100, 'temperature' => null, 'webSearch' => false, 'timeoutSeconds' => 30, ...$over,
    ]);
}

it('maps OpenAI Responses API request and usage', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response([
        'id' => 'resp_1', 'status' => 'completed',
        'output' => [
            ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed'],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"a":1}']]],
        ],
        'usage' => ['input_tokens' => 120, 'input_tokens_details' => ['cached_tokens' => 20], 'output_tokens' => 30, 'output_tokens_details' => ['reasoning_tokens' => 10]],
    ])]);

    $res = (new OpenAiProvider)->generate(req(['model' => 'gpt-6-luna', 'schema' => ['type' => 'object'], 'webSearch' => true]));

    expect($res->text)->toBe('{"a":1}')
        ->and($res->usage->inputTokens)->toBe(100)->and($res->usage->cachedInputTokens)->toBe(20)
        ->and($res->usage->outputTokens)->toBe(30)->and($res->usage->reasoningTokens)->toBe(10)
        ->and($res->usage->searches)->toBe(1)->and($res->providerRequestId)->toBe('resp_1');

    Http::assertSent(function (Request $r) {
        return $r->url() === 'https://api.openai.com/v1/responses'
            && $r->hasHeader('Authorization', 'Bearer sk-test-openai-000000000000')
            && $r['model'] === 'gpt-6-luna' && $r['input'] === 'Hello' && $r['instructions'] === 'Be brief'
            && $r['max_output_tokens'] === 100 && $r['store'] === false
            && $r['text']['format']['type'] === 'json_schema' && $r['text']['format']['strict'] === true
            && $r['tools'] === [['type' => 'web_search']];
    });
});

it('maps Claude Messages API request, usage and refusal', function () {
    Http::fake(['api.anthropic.com/v1/messages' => Http::sequence()
        ->push(['id' => 'msg_1', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Hi']],
            'usage' => ['input_tokens' => 50, 'cache_read_input_tokens' => 1000, 'cache_creation_input_tokens' => 200, 'output_tokens' => 40,
                'server_tool_use' => ['web_search_requests' => 2]]])
        ->push(['id' => 'msg_2', 'stop_reason' => 'refusal', 'content' => [], 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]]),
    ]);

    $res = (new AnthropicProvider)->generate(req(['model' => 'claude-haiku-5-5', 'schema' => ['type' => 'object'], 'webSearch' => true, 'temperature' => 0.5]));

    expect($res->text)->toBe('Hi')->and($res->usage->inputTokens)->toBe(50)->and($res->usage->cachedInputTokens)->toBe(1000)
        ->and($res->usage->cacheWriteTokens)->toBe(200)->and($res->usage->outputTokens)->toBe(40)->and($res->usage->searches)->toBe(2);

    Http::assertSent(function (Request $r) {
        return $r->hasHeader('x-api-key', 'sk-ant-test-000000000000') && $r->hasHeader('anthropic-version', '2026-10-21')
            && $r['max_tokens'] === 100 && $r['system'] === 'Be brief'
            && $r['messages'] === [['role' => 'user', 'content' => 'Hello']]
            && $r['output_config']['format']['type'] === 'json_schema'
            && $r['tools'][0]['type'] === 'web_search_20260209'
            && ! isset($r['temperature']); // rejected by API version 2026-10-21
    });

    expect(fn () => (new AnthropicProvider)->generate(req()))->toThrow(fn (ProviderException $e) => expect($e->errorCode)->toBe('refused'));
});

it('maps Gemini generateContent request and usageMetadata', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'responseId' => 'g1',
        'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => 'thinking...', 'thought' => true], ['text' => 'Xin chào']]]]],
        'usageMetadata' => ['promptTokenCount' => 300, 'cachedContentTokenCount' => 100, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 15, 'toolUsePromptTokenCount' => 5],
    ])]);

    $res = (new GeminiProvider)->generate(req(['model' => 'gemini-3.5-flash-lite', 'schema' => ['type' => 'object']]));

    expect($res->text)->toBe('Xin chào')->and($res->usage->inputTokens)->toBe(205)->and($res->usage->cachedInputTokens)->toBe(100)
        ->and($res->usage->outputTokens)->toBe(35)->and($res->usage->reasoningTokens)->toBe(15);

    Http::assertSent(function (Request $r) {
        return $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent'
            && $r->hasHeader('x-goog-api-key', 'AIzaTestGeminiKey000000000000')
            && ! str_contains($r->url(), 'key=')
            && $r['generationConfig']['maxOutputTokens'] === 100
            && $r['generationConfig']['responseMimeType'] === 'application/json'
            && $r['systemInstruction']['parts'][0]['text'] === 'Be brief';
    });
});

it('classifies HTTP errors for the retry policy and redacts secrets', function (int $status, string $code, bool $retryable) {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad key sk-test-openai-000000000000']], $status, ['Retry-After' => '3'])]);

    try {
        (new OpenAiProvider)->generate(req());
        $this->fail('expected exception');
    } catch (ProviderException $e) {
        expect($e->errorCode)->toBe($code)->and($e->retryable)->toBe($retryable)
            ->and($e->billingUncertain)->toBeFalse()
            ->and($e->getMessage())->not->toContain('sk-test-openai');
    }
})->with([
    [429, 'rate_limited', true],
    [500, 'provider_unavailable', true],
    [503, 'provider_unavailable', true],
    [401, 'auth_failed', false],
    [400, 'bad_request', false],
    [404, 'model_not_found', false],
]);

it('treats a response timeout as billing-uncertain', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'));

    expect(fn () => (new AnthropicProvider)->generate(req()))->toThrow(function (ProviderException $e) {
        expect($e->errorCode)->toBe('response_timeout')->and($e->billingUncertain)->toBeTrue();
    });
});

it('treats a connect failure as safe to retry', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to api.openai.com port 443'));

    expect(fn () => (new OpenAiProvider)->generate(req()))->toThrow(function (ProviderException $e) {
        expect($e->errorCode)->toBe('connect_failed')->and($e->billingUncertain)->toBeFalse()->and($e->retryable)->toBeTrue();
    });
});

it('refuses to call a provider without a key', function () {
    config(['sitex.ai.providers.openai.api_key' => '']);
    expect((new OpenAiProvider)->isConfigured())->toBeFalse()
        ->and(fn () => (new OpenAiProvider)->generate(req()))->toThrow(ProviderException::class);
});
