<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiBudgetReservation;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Domain\AiRequest;
use SiteXPost\Modules\AiGateway\Gateway\AiGateway;
use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\AiGateway\Gateway\AiTask;
use SiteXPost\Modules\AiGateway\Gateway\CircuitBreaker;
use SiteXPost\Modules\AiGateway\Workflows\AiTestWorkflow;
use SiteXPost\Modules\Workspace\Domain\Workspace;

beforeEach(function () {
    seedAi();
    Sleep::fake();
    Http::preventStrayRequests();
    $this->ws = createWorkspace();
});

function runAi(Workspace $ws, AiTask $task): mixed
{
    return app(TenantContext::class)->runAs($ws, null, fn () => app(AiGateway::class)->execute($task));
}

function aiRows(Workspace $ws): Collection
{
    return app(TenantContext::class)->runAs($ws, null, fn () => AiRequest::query()->orderBy('id')->get());
}

function aiCall(string $prompt, string $key = 'call-1', array $over = []): AiTask
{
    return new AiTask(...['taskKey' => 'system.test', 'prompt' => $prompt, 'callKey' => $key, ...$over]);
}

it('sends a test task and tracks tokens and cost', function () {
    routeTask('system.test', ['fake/fake-echo']);

    $result = runAi($this->ws, aiCall('Xin chào SiteX'));

    $row = aiRows($this->ws)->sole();
    expect($result->text)->toContain('Xin chào SiteX')
        ->and($result->provider)->toBe('fake')
        ->and($row->status)->toBe('succeeded')
        ->and($row->workspace_id)->toBe($this->ws->id)
        ->and($row->input_tokens)->toBeGreaterThan(0)
        ->and($row->output_tokens)->toBeGreaterThan(0)
        // fake-echo nominal price: $1 in / $2 out per 1M tokens
        ->and($row->cost_micro_usd)->toBe((int) round($row->input_tokens * 1 + $row->output_tokens * 2))
        ->and($result->costMicroUsd)->toBe($row->cost_micro_usd)
        ->and($row->prompt_hash)->toHaveLength(64)
        ->and($row->price_id)->not->toBeNull();
});

it('skips providers without a key and falls back (no network)', function () {
    // seeded route: openai → anthropic → gemini → fake; no keys in tests
    $result = runAi($this->ws, aiCall('fallback please'));

    expect($result->provider)->toBe('fake')->and($result->usedFallback)->toBeTrue()
        ->and(collect($result->attempts)->where('error_code', 'provider_not_configured'))->toHaveCount(3);
    Http::assertNothingSent();
});

it('retries a rate limit with backoff, then falls back to the next model', function () {
    config(['sitex.ai.providers.openai.api_key' => 'sk-test-openai-000000000000']);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'slow down']], 429)]);
    routeTask('system.test', ['openai/gpt-6-luna', 'fake/fake-echo']);

    $result = runAi($this->ws, aiCall('hello'));

    $rows = aiRows($this->ws);
    expect($result->provider)->toBe('fake')
        ->and($rows->pluck('status')->all())->toBe(['failed', 'failed', 'succeeded'])
        ->and($rows->pluck('error_code')->filter()->unique()->values()->all())->toBe(['rate_limited'])
        ->and($rows->last()->is_fallback)->toBeTrue();
    Http::assertSentCount(2); // 1 try + max_retries (1)
    Sleep::assertSleptTimes(1);
});

it('never retries a billing-uncertain timeout automatically', function () {
    config(['sitex.ai.providers.openai.api_key' => 'sk-test-openai-000000000000']);
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30000 ms'));
    routeTask('system.test', ['openai/gpt-6-luna', 'fake/fake-echo']);

    try {
        runAi($this->ws, aiCall('hello'));
        $this->fail('expected exception');
    } catch (AiGatewayException $e) {
        expect($e->errorCode)->toBe('response_timeout')->and($e->requiresPaidRetry)->toBeTrue()->and($e->retryable)->toBeFalse();
    }

    $row = aiRows($this->ws)->sole(); // exactly one paid attempt, no fallback
    expect($row->billing_uncertain)->toBeTrue()
        // the uncertain call is counted at its estimate, not zero
        ->and(AiBudgetReservation::query()->sole()->status)->toBe('uncertain')
        ->and(AiBudgetReservation::query()->sole()->actual_micro_usd)->toBeGreaterThan(0);
});

it('tries the next model after a timeout only when the route allows paid retries', function () {
    config(['sitex.ai.providers.openai.api_key' => 'sk-test-openai-000000000000']);
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30000 ms'));
    routeTask('system.test', ['openai/gpt-6-luna', 'fake/fake-echo'], ['allow_paid_retry' => true]);

    $result = runAi($this->ws, aiCall('hi'));

    expect($result->provider)->toBe('fake')
        ->and(aiRows($this->ws)->pluck('status')->all())->toBe(['failed', 'succeeded']); // never the same call twice
});

it('stops immediately on a refusal', function () {
    routeTask('system.test', ['fake/fake-echo']);

    expect(fn () => runAi($this->ws, aiCall('[fail:refused] x')))
        ->toThrow(fn (AiGatewayException $e) => expect($e->errorCode)->toBe('refused'));
});

it('validates structured output against the schema', function () {
    routeTask('system.test', ['fake/fake-echo']);

    $result = runAi($this->ws, aiCall('Tóm tắt', over: ['schema' => AiTestWorkflow::SCHEMA, 'schemaName' => 'test_result']));

    expect($result->json)->toHaveKeys(['summary', 'keywords', 'sentiment']);
});

it('opens the circuit after repeated failures and skips the provider', function () {
    config(['sitex.ai.providers.openai.api_key' => 'sk-test-openai-000000000000']);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'down']], 503)]);
    AiProvider::query()->where('code', 'openai')->update(['circuit_failure_threshold' => 2, 'max_retries' => 0]);
    routeTask('system.test', ['openai/gpt-6-luna', 'fake/fake-echo']);

    runAi($this->ws, aiCall('a', 'k1'));
    runAi($this->ws, aiCall('b', 'k2'));
    $third = runAi($this->ws, aiCall('c', 'k3'));

    $openai = AiProvider::query()->where('code', 'openai')->sole();
    expect(app(CircuitBreaker::class)->state($openai)['state'])->toBe('open')
        ->and(collect($third->attempts)->first()['error_code'])->toBe('circuit_open');
    Http::assertSentCount(2);

    app(CircuitBreaker::class)->reset($openai);
    expect(app(CircuitBreaker::class)->state($openai)['state'])->toBe('closed');
});

it('lets one half-open probe through after the cooldown', function () {
    $p = AiProvider::query()->where('code', 'fake')->sole();
    $p->forceFill(['circuit_failure_threshold' => 1, 'circuit_cooldown_seconds' => 30])->save();
    $cb = app(CircuitBreaker::class);

    $cb->recordFailure($p);
    expect($cb->allows($p))->toBeFalse();

    $this->travel(31)->seconds();
    expect($cb->state($p)['state'])->toBe('half_open')
        ->and($cb->allows($p))->toBeTrue()
        ->and($cb->allows($p))->toBeFalse(); // only one probe

    $cb->recordFailure($p); // probe failed → open again
    expect($cb->state($p)['state'])->toBe('open');
});

it('refuses to run the same call key twice (no double charge)', function () {
    routeTask('system.test', ['fake/fake-echo']);
    runAi($this->ws, aiCall('once', 'same-key'));

    expect(fn () => runAi($this->ws, aiCall('once', 'same-key')))
        ->toThrow(fn (AiGatewayException $e) => expect($e->errorCode)->toBe('duplicate_call'));
    expect(aiRows($this->ws))->toHaveCount(1);
});

it('rejects prompts over the limit before any call', function () {
    routeTask('system.test', ['fake/fake-echo']);
    config(['sitex.ai.max_prompt_chars' => 10]);

    expect(fn () => runAi($this->ws, aiCall(str_repeat('x', 11))))
        ->toThrow(fn (AiGatewayException $e) => expect($e->errorCode)->toBe('input_too_large'));
    expect(aiRows($this->ws))->toBeEmpty();
});

it('requires a tenant context', function () {
    expect(fn () => app(AiGateway::class)->execute(aiCall('x')))->toThrow(AiGatewayException::class);
});

it('never stores the API key in request logs', function () {
    config(['sitex.ai.providers.openai.api_key' => 'sk-test-openai-000000000000']);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided: sk-test-openai-000000000000']], 401)]);
    routeTask('system.test', ['openai/gpt-6-luna', 'fake/fake-echo']);

    runAi($this->ws, aiCall('hi'));

    $all = json_encode(aiRows($this->ws)->map->getAttributes());
    expect($all)->not->toContain('sk-test-openai-000000000000')->toContain('auth_failed');
});
