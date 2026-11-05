<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Providers\DeepSeekProvider;
use SiteXPost\Modules\AiGateway\Providers\OpenAiCompatibleProvider;
use SiteXPost\Modules\AiGateway\Providers\ProviderException;
use SiteXPost\Modules\AiGateway\Providers\ProviderRequest;

/**
 * DeepSeek request/response shapes follow api-docs.deepseek.com (read
 * 05/11/2026): POST /chat/completions, Bearer auth, response_format
 * json_object only, thinking {type}, reasoning_effort, usage with
 * prompt_cache_hit_tokens and completion_tokens_details.reasoning_tokens.
 * No real network call is made.
 */
beforeEach(function () {
    config(['sitex.ai.providers.deepseek.api_key' => 'sk-deepseek-test-0000000000']);
    Http::preventStrayRequests();
});

function dsReq(array $over = []): ProviderRequest
{
    return new ProviderRequest(...[
        'model' => 'deepseek-flash', 'prompt' => 'Xin chào', 'system' => 'Ngắn gọn', 'schema' => null, 'schemaName' => 'result',
        'maxOutputTokens' => 100, 'temperature' => null, 'webSearch' => false, 'timeoutSeconds' => 30, ...$over,
    ]);
}

function dsReply(array $over = []): array
{
    return [
        'id' => 'ds-1', 'object' => 'chat.completion', 'model' => 'deepseek-flash',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"a":1}'], 'finish_reason' => 'stop']],
        'usage' => [
            'prompt_tokens' => 100, 'completion_tokens' => 30, 'total_tokens' => 130,
            'prompt_cache_hit_tokens' => 40, 'prompt_cache_miss_tokens' => 60,
            'completion_tokens_details' => ['reasoning_tokens' => 0],
        ],
        ...$over,
    ];
}

function customProvider(array $attrs = []): AiProvider
{
    $p = new AiProvider;
    $p->forceFill(['code' => 'acme', 'driver' => 'openai_compatible', 'name' => 'Acme', 'base_url' => 'https://llm.acme.test/v1',
        'is_custom' => true, 'settings' => ['response_format' => 'json_schema'], ...$attrs]);
    $p->setApiKey('sk-acme-db-key-1234');

    return $p;
}

it('maps the DeepSeek chat completions request, JSON mode and usage', function () {
    Http::fake(['api.deepseek.com/chat/completions' => Http::response(dsReply())]);

    $res = (new DeepSeekProvider)->generate(dsReq(['schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]], 'temperature' => 0.3]));

    expect($res->text)->toBe('{"a":1}')->and($res->finishReason)->toBe('stop')->and($res->providerRequestId)->toBe('ds-1')
        ->and($res->usage->inputTokens)->toBe(60)->and($res->usage->cachedInputTokens)->toBe(40)
        ->and($res->usage->outputTokens)->toBe(30);

    Http::assertSent(function (Request $r) {
        $system = $r['messages'][0];

        return $r->url() === 'https://api.deepseek.com/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer sk-deepseek-test-0000000000')
            && $r['model'] === 'deepseek-flash' && $r['max_tokens'] === 100 && $r['stream'] === false
            && $r['response_format'] === ['type' => 'json_object']          // DeepSeek has no json_schema mode
            && $system['role'] === 'system' && str_contains($system['content'], 'JSON Schema')
            && $r['messages'][1] === ['role' => 'user', 'content' => 'Xin chào']
            && $r['thinking'] === ['type' => 'disabled'] && $r['temperature'] === 0.3;
    });
});

it('enables DeepSeek thinking with the configured effort and drops temperature', function () {
    Http::fake(['api.deepseek.com/chat/completions' => Http::response(dsReply(['usage' => [
        'prompt_tokens' => 10, 'completion_tokens' => 50, 'prompt_tokens_details' => ['cached_tokens' => 0],
        'completion_tokens_details' => ['reasoning_tokens' => 35],
    ]]))]);

    $res = (new DeepSeekProvider)->generate(dsReq(['reasoningEffort' => 'high', 'temperature' => 0.7]));

    expect($res->usage->reasoningTokens)->toBe(35)->and($res->usage->outputTokens)->toBe(50);
    Http::assertSent(fn (Request $r) => $r['thinking'] === ['type' => 'enabled'] && $r['reasoning_effort'] === 'high'
        && ! isset($r['temperature']) && ! isset($r['response_format']));
});

it('classifies DeepSeek content filter and capacity aborts', function () {
    Http::fake(['api.deepseek.com/chat/completions' => Http::sequence()
        ->push(dsReply(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'content_filter']]]))
        ->push(dsReply(['choices' => [['message' => ['content' => 'par'], 'finish_reason' => 'insufficient_system_resource']]]))
        ->push(['error' => ['message' => 'Authentication Fails (sk-deepseek-test-0000000000)']], 401),
    ]);
    $p = new DeepSeekProvider;

    expect(fn () => $p->generate(dsReq()))->toThrow(fn (ProviderException $e) => expect($e->errorCode)->toBe('refused')->and($e->usage)->not->toBeNull());
    expect(fn () => $p->generate(dsReq()))->toThrow(fn (ProviderException $e) => expect($e->errorCode)->toBe('provider_overloaded')
        ->and($e->billingUncertain)->toBeTrue()->and($e->retryable)->toBeTrue());
    expect(fn () => $p->generate(dsReq()))->toThrow(fn (ProviderException $e) => expect($e->errorCode)->toBe('auth_failed')
        ->and($e->getMessage())->not->toContain('sk-deepseek-test'));
});

it('lists DeepSeek models for Test Connection', function () {
    Http::fake(['api.deepseek.com/models' => Http::response(['object' => 'list', 'data' => [
        ['id' => 'deepseek-flash', 'object' => 'model', 'owned_by' => 'deepseek'],
        ['id' => 'deepseek-v4-pro', 'object' => 'model', 'owned_by' => 'deepseek'],
    ]])]);

    expect((new DeepSeekProvider)->listModels())->toBe(['deepseek-flash', 'deepseek-v4-pro']);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer sk-deepseek-test-0000000000'));
});

it('sends a custom OpenAI-compatible call to the row base URL with the stored key and json_schema mode', function () {
    app()->instance(SsrfGuard::class, new SsrfGuard([], false, static fn (string $host): array => ['93.184.216.34']));
    Http::fake(['llm.acme.test/v1/chat/completions' => Http::response(dsReply(['usage' => [
        'prompt_tokens' => 20, 'completion_tokens' => 5, 'prompt_tokens_details' => ['cached_tokens' => 8],
    ]]))]);

    $adapter = (new OpenAiCompatibleProvider)->forProvider(customProvider());
    $res = $adapter->generate(dsReq(['model' => 'acme-large', 'schema' => ['type' => 'object'], 'schemaName' => 'x']));

    expect($adapter->isConfigured())->toBeTrue()->and($res->usage->cachedInputTokens)->toBe(8)->and($res->usage->inputTokens)->toBe(12);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://llm.acme.test/v1/chat/completions'
        && $r->hasHeader('Authorization', 'Bearer sk-acme-db-key-1234')
        && $r['response_format']['type'] === 'json_schema' && $r['response_format']['json_schema']['name'] === 'x'
        && ! isset($r['thinking']) && ! str_contains($r['messages'][0]['content'], 'JSON Schema'));
});

it('refuses a custom base URL that resolves to a private address, without sending anything', function () {
    app()->instance(SsrfGuard::class, new SsrfGuard([], false, static fn (string $host): array => ['10.0.0.5']));
    Http::fake();

    $adapter = (new OpenAiCompatibleProvider)->forProvider(customProvider());

    expect(fn () => $adapter->generate(dsReq()))->toThrow(fn (ProviderException $e) => expect($e->errorCode)->toBe('blocked_url'));
    Http::assertNothingSent();
});

it('prefers the stored key over .env and falls back to .env when none is stored', function () {
    config(['sitex.ai.providers.deepseek.api_key' => 'sk-env-deepseek-9999']);
    $p = new AiProvider;
    $p->forceFill(['code' => 'deepseek', 'driver' => 'deepseek', 'name' => 'DeepSeek']);

    expect($p->apiKey())->toBe('sk-env-deepseek-9999')->and($p->apiKeySource())->toBe('env');
    $p->setApiKey('sk-db-deepseek-1111');
    expect($p->apiKey())->toBe('sk-db-deepseek-1111')->and($p->apiKeySource())->toBe('database')->and($p->api_key_last4)->toBe('1111')
        ->and($p->toArray())->not->toHaveKey('api_key_encrypted');
});

it('reports a refused proxy tunnel as connect_failed and a free model-list timeout as a plain network error', function () {
    Http::fake(['api.deepseek.com/models' => Http::sequence()
        ->pushFailedConnection('cURL error 56: CONNECT tunnel failed, response 403')
        ->pushFailedConnection('cURL error 28: Operation timed out after 15000 milliseconds with 0 bytes received')]);
    $p = new DeepSeekProvider;

    expect(fn () => $p->listModels())->toThrow(fn (ProviderException $e) => expect($e->errorCode)->toBe('connect_failed')
        ->and($e->billingUncertain)->toBeFalse());
    expect(fn () => $p->listModels())->toThrow(fn (ProviderException $e) => expect($e->errorCode)->toBe('network_error')
        ->and($e->billingUncertain)->toBeFalse()->and($e->retryable)->toBeTrue());
});
