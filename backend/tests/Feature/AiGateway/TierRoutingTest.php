<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Gateway\AiGateway;
use SiteXPost\Modules\AiGateway\Gateway\AiTask;
use SiteXPost\Modules\AiGateway\Gateway\ModelSelector;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;

/*
 * v1.0.13 — a task route names a model level; switching models on/off on the
 * Providers page flows into every route without re-editing it.
 */

beforeEach(function () {
    seedAi();
    Sleep::fake();
    $this->admin = User::factory()->platform(PlatformRole::SuperAdmin, true)->create();
});

function onlyAnthropicKey(): void
{
    config(['sitex.ai.fake_provider' => false, 'sitex.ai.providers.anthropic.api_key' => 'sk-ant-test-000000000000']);
}

function tierRoute(string $key): AiTaskRoute
{
    return AiTaskRoute::query()->where('task_key', $key)->sole();
}

it('orders models by level: chosen level, then cheaper, then stronger; pinned first; fake last', function () {
    $selector = app(ModelSelector::class);
    $r = tierRoute('writer.outline');
    $r->forceFill(['tier' => 'standard', 'pinned_model_id' => null])->save();

    $tiers = array_map(fn (AiModel $m) => $m->provider->code === 'fake' ? 'fake' : $m->tier, $selector->ordered($r));
    $firstFake = array_search('fake', $tiers, true);
    $real = array_slice($tiers, 0, $firstFake);
    expect(array_values(array_unique($real)))->toBe(['standard', 'economy', 'premium'])
        ->and(array_unique(array_slice($tiers, $firstFake)))->toBe(['fake']);

    $r->forceFill(['pinned_model_id' => aiModel('anthropic/claude-opus-5-5')->id])->save();
    expect($selector->ordered($r)[0]->model_key)->toBe('anthropic/claude-opus-5-5');

    $r->forceFill(['tier' => 'premium', 'pinned_model_id' => null])->save();
    expect(array_values(array_unique(array_map(fn (AiModel $m) => $m->tier, array_filter($selector->ordered($r), fn (AiModel $m) => $m->provider->code !== 'fake')))))
        ->toBe(['premium', 'standard', 'economy']);
});

it('runs on Haiku when Sonnet is switched off, with no route edits (only an Anthropic key)', function () {
    onlyAnthropicKey();
    aiModel('anthropic/claude-sonnet-5-5')->forceFill(['is_enabled' => false])->save();
    aiModel('anthropic/claude-opus-5-5')->forceFill(['is_enabled' => false])->save();
    tierRoute('writer.outline')->forceFill(['tier' => 'standard'])->save();
    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => 'Xin chào']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ])]);

    $ws = createWorkspace();
    $result = app(TenantContext::class)->runAs($ws, null, fn () => app(AiGateway::class)->execute(new AiTask('writer.outline', 'hi', 'tier-1')));

    expect($result->modelKey)->toBe('anthropic/claude-haiku-5-5')->and($result->usedFallback)->toBeFalse();

    $res = $this->actingAs($this->admin)->getJson('/api/admin/v1/ai/routes')->assertOk();
    $rows = collect($res->json('data'))->keyBy('task_key');
    expect($rows['writer.outline']['resolved']['status'])->toBe('substitute')
        ->and($rows['writer.outline']['resolved']['runs_on']['model_key'])->toBe('anthropic/claude-haiku-5-5')
        // web search needs Sonnet/Opus: blocked with a clear reason, not silently on Haiku
        ->and($rows['writer.source_discovery']['resolved']['status'])->toBe('blocked')
        ->and($rows['writer.source_discovery']['resolved']['blocked_reason'])->toBe('needs_web_search');
    $blocked = collect($res->json('data'))->filter(fn (array $r) => $r['kind'] === 'text' && $r['resolved']['status'] === 'blocked')->pluck('task_key')->all();
    expect($blocked)->toBe(['writer.source_discovery']);

    // switching Sonnet back on flows into the route at once
    aiModel('anthropic/claude-sonnet-5-5')->forceFill(['is_enabled' => true])->save();
    $row = collect($this->actingAs($this->admin)->getJson('/api/admin/v1/ai/routes')->json('data'))->firstWhere('task_key', 'writer.outline');
    expect($row['resolved']['status'])->toBe('exact')->and($row['resolved']['runs_on']['model_key'])->toBe('anthropic/claude-sonnet-5-5');
});

it('falls back to the level when the pinned model is switched off', function () {
    onlyAnthropicKey();
    $r = tierRoute('writer.outline');
    $r->forceFill(['tier' => 'standard', 'pinned_model_id' => aiModel('anthropic/claude-opus-5-5')->id])->save();
    aiModel('anthropic/claude-opus-5-5')->forceFill(['is_enabled' => false])->save();

    $res = app(ModelSelector::class)->resolve($r->fresh());
    expect($res['pinned_reason'])->toBe('model_off')
        ->and($res['runs_on']['model_key'])->toBe('anthropic/claude-sonnet-5-5')
        ->and($res['status'])->toBe('substitute');
});

it('migrates existing routes to the level of their old primary model', function () {
    $r = tierRoute('writer.outline');
    expect($r->tier)->toBe(aiModel('anthropic/claude-sonnet-5-5')->tier)->and($r->kind)->toBe('text')->and($r->pinned_model_id)->toBeNull()
        ->and(tierRoute('media.image')->kind)->toBe('image')
        ->and(tierRoute('writer.source_discovery')->requires_web_search)->toBeTrue();
});
