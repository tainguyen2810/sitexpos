<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiBudgetCounter;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Domain\AiRequest;
use SiteXPost\Modules\AiGateway\Gateway\AiGateway;
use SiteXPost\Modules\AiGateway\Gateway\AiTask;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;

/**
 * Phase 3E: DeepSeek, custom OpenAI-compatible providers, encrypted API keys,
 * Test Connection / Test Model, Model ID edit/delete, off-peak price versions.
 */
beforeEach(function () {
    seedAi();
    Sleep::fake();
    Http::preventStrayRequests();
    fakePublicDns();
    $this->admin = User::factory()->platform(PlatformRole::SuperAdmin, true)->create();
    $this->deepseek = AiProvider::query()->where('code', 'deepseek')->sole();
});

function deepseekCompletion(string $content = 'Bằng 4.', array $usage = []): array
{
    return [
        'id' => 'ds-'.bin2hex(random_bytes(3)),
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 1000, 'prompt_cache_hit_tokens' => 0, 'prompt_cache_miss_tokens' => 1000, ...$usage],
    ];
}

it('seeds DeepSeek with the documented model IDs, disabled, with peak and off-peak prices', function () {
    expect($this->deepseek->driver)->toBe('deepseek')->and($this->deepseek->hasApiKey())->toBeFalse();

    $flash = aiModel('deepseek/deepseek-flash');
    $pro = aiModel('deepseek/deepseek-v4-pro');
    $price = $flash->priceAt();

    expect($flash->is_enabled)->toBeFalse()->and($pro->is_enabled)->toBeFalse()
        ->and($flash->supports('vision'))->toBeTrue()->and($pro->supports('vision'))->toBeFalse()
        ->and($flash->supports('web_search'))->toBeFalse()
        ->and($price->input_per_mtok)->toBe(300_000)->and($price->offpeak_input_per_mtok)->toBe(150_000)
        ->and($price->output_per_mtok)->toBe(1_200_000)->and($price->offpeak_output_per_mtok)->toBe(600_000)
        ->and($price->hasOffPeak())->toBeTrue()
        ->and($pro->priceAt()->output_per_mtok)->toBe(3_960_000);
});

it('stores an API key encrypted, never returns it, and can clear it', function () {
    $url = "/api/admin/v1/ai/providers/{$this->deepseek->id}";

    $res = $this->actingAs($this->admin)->patchJson($url, ['api_key' => 'sk-deepseek-secret-abcd1234'])->assertOk()
        ->assertJsonPath('data.has_api_key', true)->assertJsonPath('data.api_key_last4', '1234')->assertJsonPath('data.api_key_source', 'database');

    $raw = DB::table('ai_providers')->where('id', $this->deepseek->id)->value('api_key_encrypted');
    expect($res->getContent())->not->toContain('sk-deepseek-secret')
        ->and($raw)->not->toBeNull()->and($raw)->not->toContain('sk-deepseek-secret')
        ->and($this->deepseek->fresh()->apiKey())->toBe('sk-deepseek-secret-abcd1234')
        ->and($this->actingAs($this->admin)->getJson('/api/admin/v1/ai/providers')->getContent())->not->toContain('sk-deepseek-secret');

    // Audit log records the change without the key.
    expect(DB::table('audit_logs')->where('action', 'platform.ai.provider.updated')->pluck('changes')->implode(' '))->not->toContain('sk-deepseek-secret');

    config(['sitex.ai.providers.deepseek.api_key' => 'sk-env-fallback-0000']);
    $this->actingAs($this->admin)->patchJson($url, ['clear_api_key' => true])->assertOk()
        ->assertJsonPath('data.api_key_source', 'env')->assertJsonPath('data.api_key_last4', null);
});

it('tests the connection with a free model-list call and flags unknown Model IDs', function () {
    $this->deepseek->setApiKey('sk-deepseek-secret-abcd1234');
    $this->deepseek->save();
    Http::fake(['api.deepseek.com/models' => Http::response(['object' => 'list', 'data' => [['id' => 'deepseek-flash', 'object' => 'model', 'owned_by' => 'deepseek']]])]);

    $res = $this->actingAs($this->admin)->postJson("/api/admin/v1/ai/providers/{$this->deepseek->id}/test-connection")->assertOk();

    expect($res->json('data.ok'))->toBeTrue()->and($res->json('data.models_found'))->toBe(1)
        ->and(collect($res->json('data.models'))->pluck('available', 'model_name')->all())->toBe(['deepseek-flash' => true, 'deepseek-v4-pro' => false]);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer sk-deepseek-secret-abcd1234'));

    $openai = AiProvider::query()->where('code', 'openai')->sole();
    $this->actingAs($this->admin)->postJson("/api/admin/v1/ai/providers/{$openai->id}/test-connection")->assertOk()
        ->assertJsonPath('data.ok', false)->assertJsonPath('data.error_code', 'provider_not_configured');
});

it('runs a real Test Model call on a disabled model: global budget, cost, platform log row', function () {
    $this->deepseek->setApiKey('sk-deepseek-secret-abcd1234');
    $this->deepseek->save();
    aiBudget(null, 50_000_000);
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00:00', 'UTC')); // Saturday → off-peak
    Http::fake(['api.deepseek.com/chat/completions' => Http::response(deepseekCompletion('{"answer":"4","confident":true}'))]);
    $flash = aiModel('deepseek/deepseek-flash');

    $res = $this->actingAs($this->admin)->postJson("/api/admin/v1/ai/models/{$flash->id}/test", ['structured' => true])->assertOk();

    // 1000 in × $0.15 + 1000 out × $0.60 (off-peak) = 150 + 600 micro-USD
    expect($res->json('data.ok'))->toBeTrue()->and($res->json('data.json'))->toBe(['answer' => '4', 'confident' => true])
        ->and($res->json('data.cost_micro_usd'))->toBe(750)
        ->and($flash->fresh()->is_enabled)->toBeFalse();

    $row = app(TenantContext::class)->bypass(fn () => AiRequest::query()->where('task_key', 'system.model_test')->sole());
    expect($row->workspace_id)->toBeNull()->and($row->status)->toBe('succeeded')->and($row->cost_micro_usd)->toBe(750)
        ->and(AiBudgetCounter::query()->where('scope_key', 'global')->sole()->spent_micro_usd)->toBe(750);
    Http::assertSent(fn (Request $r) => $r['response_format'] === ['type' => 'json_object'] && $r['thinking'] === ['type' => 'disabled']);
});

it('reports a failed Test Model call without leaking the key', function () {
    $this->deepseek->setApiKey('sk-deepseek-secret-abcd1234');
    $this->deepseek->save();
    Http::fake(['api.deepseek.com/chat/completions' => Http::response(['error' => ['message' => 'Authentication Fails, key sk-deepseek-secret-abcd1234 is invalid']], 401)]);

    $res = $this->actingAs($this->admin)->postJson('/api/admin/v1/ai/models/'.aiModel('deepseek/deepseek-flash')->id.'/test')->assertOk();

    expect($res->json('data.ok'))->toBeFalse()->and($res->json('data.error_code'))->toBe('auth_failed')
        ->and($res->getContent())->not->toContain('sk-deepseek-secret');
    $row = app(TenantContext::class)->bypass(fn () => AiRequest::query()->where('task_key', 'system.model_test')->sole());
    expect($row->error_message)->not->toContain('sk-deepseek-secret');
});

it('falls back from DeepSeek to the next model when DeepSeek is down', function () {
    $this->deepseek->setApiKey('sk-deepseek-secret-abcd1234');
    $this->deepseek->save();
    $flash = aiModel('deepseek/deepseek-flash');
    $flash->forceFill(['is_enabled' => true])->save();
    routeTask('system.test', ['deepseek/deepseek-flash', 'fake/fake-echo']);
    $this->deepseek->forceFill(['max_retries' => 0])->save();
    Http::fake(['api.deepseek.com/chat/completions' => Http::response(['error' => ['message' => 'Service Unavailable']], 503)]);
    $ws = createWorkspace();

    $result = app(TenantContext::class)->runAs($ws, null, fn () => app(AiGateway::class)->execute(new AiTask(taskKey: 'system.test', prompt: 'Xin chào', callKey: 'fb-1')));

    expect($result->usedFallback)->toBeTrue()->and($result->modelKey)->toBe('fake/fake-echo')
        ->and(collect($result->attempts)->pluck('error_code')->filter()->values()->all())->toBe(['provider_unavailable']);
});

it('creates a custom OpenAI-compatible provider, rejecting private or built-in endpoints', function () {
    $res = $this->actingAs($this->admin)->postJson('/api/admin/v1/ai/providers', [
        'name' => 'Acme LLM', 'code' => 'acme', 'base_url' => 'https://llm.acme.test/v1/', 'api_key' => 'sk-acme-secret-9876', 'response_format' => 'json_schema',
    ])->assertCreated()
        ->assertJsonPath('data.driver', 'openai_compatible')->assertJsonPath('data.is_custom', true)->assertJsonPath('data.is_enabled', false)
        ->assertJsonPath('data.base_url', 'https://llm.acme.test/v1')->assertJsonPath('data.response_format', 'json_schema')
        ->assertJsonPath('data.api_key_last4', '9876');
    expect($res->getContent())->not->toContain('sk-acme-secret');

    $acme = AiProvider::query()->where('code', 'acme')->sole();
    // A model on it can be added, priced, tested and routed like any other.
    $model = $this->actingAs($this->admin)->postJson('/api/admin/v1/ai/models', ['provider_id' => $acme->id, 'model_name' => 'acme-large', 'display_name' => 'Acme Large',
        'capabilities' => ['structured_output' => true]])->assertCreated()->json('data');
    $this->actingAs($this->admin)->postJson("/api/admin/v1/ai/models/{$model['id']}/prices", ['effective_from' => now()->toIso8601String(), 'input_per_mtok' => 1_000_000, 'output_per_mtok' => 2_000_000])->assertCreated();
    Http::fake(['llm.acme.test/v1/chat/completions' => Http::response(deepseekCompletion('{"answer":"4","confident":true}'))]);
    $this->actingAs($this->admin)->postJson("/api/admin/v1/ai/models/{$model['id']}/test", ['structured' => true])->assertOk()->assertJsonPath('data.ok', true);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://llm.acme.test/v1/chat/completions' && $r->hasHeader('Authorization', 'Bearer sk-acme-secret-9876')
        && $r['response_format']['type'] === 'json_schema');

    fakePublicDns('10.0.0.5');
    $this->actingAs($this->admin)->postJson('/api/admin/v1/ai/providers', ['name' => 'Internal', 'code' => 'internal', 'base_url' => 'https://internal.acme.test/v1'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['base_url']]]);
    $this->actingAs($this->admin)->postJson('/api/admin/v1/ai/providers', ['name' => 'Plain', 'code' => 'plain', 'base_url' => 'http://llm.acme.test/v1'])->assertStatus(422);
    $this->actingAs($this->admin)->postJson('/api/admin/v1/ai/providers', ['name' => 'X', 'code' => 'deepseek', 'base_url' => 'https://llm.acme.test'])->assertStatus(422);
    $this->actingAs($this->admin)->patchJson("/api/admin/v1/ai/providers/{$this->deepseek->id}", ['base_url' => 'https://llm.acme.test'])->assertStatus(422);

    // Delete: custom provider with models → 409; built-in → 409.
    $this->actingAs($this->admin)->deleteJson("/api/admin/v1/ai/providers/{$acme->id}")->assertConflict();
    $this->actingAs($this->admin)->deleteJson("/api/admin/v1/ai/providers/{$this->deepseek->id}")->assertConflict();
});

it('deletes an unused custom provider', function () {
    $id = $this->actingAs($this->admin)->postJson('/api/admin/v1/ai/providers', ['name' => 'Tmp', 'code' => 'tmp', 'base_url' => 'https://tmp.acme.test/v1'])->json('data.id');

    $this->actingAs($this->admin)->deleteJson("/api/admin/v1/ai/providers/{$id}")->assertNoContent();
    expect(AiProvider::query()->find($id))->toBeNull();
});

it('edits a Model ID and deletes only models that were never used', function () {
    $pro = aiModel('deepseek/deepseek-v4-pro');

    $this->actingAs($this->admin)->patchJson("/api/admin/v1/ai/models/{$pro->id}", ['model_name' => 'deepseek-v4-pro-next', 'capabilities' => ['reasoning_effort' => 'high']])
        ->assertOk()->assertJsonPath('data.model_name', 'deepseek-v4-pro-next')->assertJsonPath('data.model_key', 'deepseek/deepseek-v4-pro-next')
        ->assertJsonPath('data.capabilities.reasoning_effort', 'high');
    $this->actingAs($this->admin)->patchJson("/api/admin/v1/ai/models/{$pro->id}", ['model_name' => 'deepseek-flash'])->assertConflict();

    // Used by a route → cannot delete.
    $this->actingAs($this->admin)->deleteJson('/api/admin/v1/ai/models/'.aiModel('fake/fake-echo')->id)->assertConflict();

    $this->actingAs($this->admin)->deleteJson("/api/admin/v1/ai/models/{$pro->id}")->assertNoContent();
    expect(AiModel::query()->find($pro->id))->toBeNull();
});

it('validates off-peak price versions', function () {
    $flash = aiModel('deepseek/deepseek-flash');
    $url = "/api/admin/v1/ai/models/{$flash->id}/prices";
    $base = ['effective_from' => now()->addDay()->toIso8601String(), 'input_per_mtok' => 300_000, 'output_per_mtok' => 1_200_000];

    $this->actingAs($this->admin)->postJson($url, [...$base, 'offpeak_input_per_mtok' => 150_000, 'offpeak_output_per_mtok' => 600_000])
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['peak_windows']]]);
    $this->actingAs($this->admin)->postJson($url, [...$base, 'offpeak_input_per_mtok' => 1, 'offpeak_output_per_mtok' => 1,
        'peak_windows' => [['days' => [1], 'from' => '10:00', 'to' => '06:00']]])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['peak_windows.0.to']]]);

    $this->actingAs($this->admin)->postJson($url, [...$base, 'offpeak_input_per_mtok' => 150_000, 'offpeak_cached_input_per_mtok' => 3_000, 'offpeak_output_per_mtok' => 600_000,
        'peak_windows' => [['days' => [1, 2, 3, 4, 5], 'from' => '01:00', 'to' => '04:00'], ['days' => [1, 2, 3, 4, 5], 'from' => '06:00', 'to' => '10:00']]])
        ->assertCreated()->assertJsonPath('data.offpeak_output_per_mtok', 600_000)->assertJsonPath('data.peak_windows.0.from', '01:00')
        ->assertJsonPath('data.state', 'scheduled');
});

it('keeps provider management and tests to Super Admin', function () {
    $support = User::factory()->platform(PlatformRole::Support, true)->create();
    $billing = User::factory()->platform(PlatformRole::BillingAdmin, true)->create();
    $flash = aiModel('deepseek/deepseek-flash');

    foreach ([$support, $billing] as $user) {
        $this->actingAs($user)->postJson('/api/admin/v1/ai/providers', ['name' => 'X', 'code' => 'xx', 'base_url' => 'https://x.acme.test'])->assertForbidden();
        $this->actingAs($user)->patchJson("/api/admin/v1/ai/providers/{$this->deepseek->id}", ['api_key' => 'sk-should-not-save-1'])->assertForbidden();
        $this->actingAs($user)->postJson("/api/admin/v1/ai/providers/{$this->deepseek->id}/test-connection")->assertForbidden();
        $this->actingAs($user)->postJson("/api/admin/v1/ai/models/{$flash->id}/test")->assertForbidden();
    }
    expect($this->deepseek->fresh()->api_key_last4)->toBeNull();
});
