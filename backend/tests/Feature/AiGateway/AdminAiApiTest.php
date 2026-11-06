<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiModelPrice;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Domain\AiRequest;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Gateway\AiGateway;
use SiteXPost\Modules\AiGateway\Gateway\AiTask;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Workflows\Domain\DeadLetter;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\WorkflowEngine;

beforeEach(function () {
    seedAi();
    Sleep::fake();
    $this->admin = User::factory()->platform(PlatformRole::SuperAdmin, true)->create();
});

it('hides AI and job administration from customers and enforces platform permissions', function () {
    $customer = User::factory()->create();
    createWorkspace($customer);
    $support = User::factory()->platform(PlatformRole::Support, true)->create();
    $billing = User::factory()->platform(PlatformRole::BillingAdmin, true)->create();

    $this->actingAs($customer)->getJson('/api/admin/v1/ai/providers')->assertNotFound();
    $this->actingAs($customer)->getJson('/api/admin/v1/jobs/overview')->assertNotFound();

    $this->actingAs($support)->getJson('/api/admin/v1/ai/providers')->assertForbidden();
    $this->actingAs($support)->getJson('/api/admin/v1/jobs/overview')->assertOk();
    $this->actingAs($billing)->getJson('/api/admin/v1/ai/usage')->assertOk();
    $this->actingAs($billing)->getJson('/api/admin/v1/ai/budgets')->assertOk();
    $this->actingAs($billing)->putJson('/api/admin/v1/ai/budgets', ['scope' => 'global', 'period' => 'month', 'limit_micro_usd' => 1])->assertForbidden();
    $this->actingAs($billing)->getJson('/api/admin/v1/jobs/overview')->assertForbidden();
});

it('lists providers without ever exposing API keys', function () {
    config(['sitex.ai.providers.openai.api_key' => 'sk-live-should-never-leak-123456']);

    $res = $this->actingAs($this->admin)->getJson('/api/admin/v1/ai/providers')->assertOk();

    expect($res->getContent())->not->toContain('sk-live-should-never-leak')
        ->and(collect($res->json('data'))->firstWhere('code', 'openai')['has_api_key'])->toBeTrue()
        ->and(collect($res->json('data'))->firstWhere('code', 'anthropic')['has_api_key'])->toBeFalse();

    $openai = AiProvider::query()->where('code', 'openai')->sole();
    $this->actingAs($this->admin)->patchJson("/api/admin/v1/ai/providers/{$openai->id}", ['api_key' => 'x'])->assertStatus(422);
    $this->actingAs($this->admin)->patchJson("/api/admin/v1/ai/providers/{$openai->id}", ['base_url' => 'https://evil.test'])->assertStatus(422);
    $this->actingAs($this->admin)->patchJson("/api/admin/v1/ai/providers/{$openai->id}", ['is_enabled' => false, 'timeout_seconds' => 45])
        ->assertOk()->assertJsonPath('data.is_enabled', false)->assertJsonPath('data.timeout_seconds', 45);
    $this->actingAs($this->admin)->postJson("/api/admin/v1/ai/providers/{$openai->id}/circuit/reset")->assertOk()->assertJsonPath('data.circuit.state', 'closed');
});

it('registers a model disabled and requires a price before enabling it', function () {
    $provider = AiProvider::query()->where('code', 'openai')->sole();

    $id = $this->actingAs($this->admin)->postJson('/api/admin/v1/ai/models', [
        'provider_id' => $provider->id, 'model_name' => 'gpt-test-1', 'display_name' => 'GPT Test',
        'capabilities' => ['structured_output' => true],
    ])->assertCreated()->assertJsonPath('data.is_enabled', false)->assertJsonPath('data.model_key', 'openai/gpt-test-1')->json('data.id');

    $this->actingAs($this->admin)->patchJson("/api/admin/v1/ai/models/{$id}", ['is_enabled' => true])
        ->assertStatus(409)->assertJsonPath('error.code', 'price_missing');

    $this->actingAs($this->admin)->postJson("/api/admin/v1/ai/models/{$id}/prices", [
        'effective_from' => now()->toDateTimeString(), 'input_per_mtok' => 1_000_000, 'output_per_mtok' => 4_000_000,
        'source_url' => 'https://developers.openai.com/api/docs/pricing',
    ])->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.state', 'effective');

    $this->actingAs($this->admin)->patchJson("/api/admin/v1/ai/models/{$id}", ['is_enabled' => true])->assertOk()->assertJsonPath('data.is_enabled', true);
});

it('versions prices: effective versions are locked, scheduled ones can be removed', function () {
    $model = aiModel('fake/fake-echo');
    $effective = AiModelPrice::query()->where('model_id', $model->id)->sole();

    $scheduled = $this->actingAs($this->admin)->postJson("/api/admin/v1/ai/models/{$model->id}/prices", [
        'effective_from' => now()->addMonth()->toDateTimeString(), 'input_per_mtok' => 2_000_000, 'output_per_mtok' => 3_000_000,
    ])->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.state', 'scheduled')->json('data.id');

    $this->actingAs($this->admin)->deleteJson("/api/admin/v1/ai/models/{$model->id}/prices/{$effective->id}")
        ->assertStatus(409)->assertJsonPath('error.code', 'price_version_locked');
    $this->actingAs($this->admin)->deleteJson("/api/admin/v1/ai/models/{$model->id}/prices/{$scheduled}")->assertNoContent();

    // the gateway bills with the version effective at call time and records it
    routeTask('system.test', ['fake/fake-echo']);
    $ws = createWorkspace();
    app(TenantContext::class)->runAs($ws, null, fn () => app(AiGateway::class)->execute(new AiTask('system.test', 'hello', 'price-1')));
    $row = app(TenantContext::class)->runAs($ws, null, fn () => AiRequest::query()->sole());
    expect($row->price_id)->toBe($effective->id);
});

it('switches a task route to other models and refuses unknown queues', function () {
    $route = AiTaskRoute::query()->where('task_key', 'system.test')->sole();
    $claude = aiModel('anthropic/claude-haiku-5-5');
    $fake = aiModel('fake/fake-echo');

    $this->actingAs($this->admin)->patchJson("/api/admin/v1/ai/routes/{$route->id}", [
        'primary_model_id' => $claude->id, 'fallback_model_ids' => [$fake->id], 'max_attempts' => 2, 'allow_paid_retry' => true,
    ])->assertOk()
        ->assertJsonPath('data.primary_model.model_key', 'anthropic/claude-haiku-5-5')
        ->assertJsonPath('data.fallback_models.0.model_key', 'fake/fake-echo')
        ->assertJsonPath('data.allow_paid_retry', true);

    $this->actingAs($this->admin)->patchJson("/api/admin/v1/ai/routes/{$route->id}", ['queue' => 'nope'])->assertStatus(422);
});

it('manages global and per-tenant budgets', function () {
    $ws = createWorkspace(name: 'Budget Co');

    $this->actingAs($this->admin)->putJson('/api/admin/v1/ai/budgets', ['scope' => 'global', 'period' => 'month', 'limit_micro_usd' => 500_000_000])
        ->assertCreated()->assertJsonPath('data.scope', 'global');
    $id = $this->actingAs($this->admin)->putJson('/api/admin/v1/ai/budgets', [
        'scope' => 'workspace', 'workspace_id' => $ws->id, 'period' => 'day', 'limit_micro_usd' => 1_000_000, 'action_on_exceed' => 'pause_batch',
    ])->assertCreated()->assertJsonPath('data.workspace.name', 'Budget Co')->json('data.id');
    $this->actingAs($this->admin)->putJson('/api/admin/v1/ai/budgets', [
        'scope' => 'workspace', 'workspace_id' => $ws->id, 'period' => 'day', 'limit_micro_usd' => 2_000_000,
    ])->assertOk()->assertJsonPath('data.limit_micro_usd', 2_000_000);

    $this->actingAs($this->admin)->getJson('/api/admin/v1/ai/budgets')->assertOk()->assertJsonCount(2, 'data');
    $this->actingAs($this->admin)->putJson('/api/admin/v1/ai/budgets', ['scope' => 'global', 'workspace_id' => $ws->id, 'period' => 'day', 'limit_micro_usd' => 1])->assertStatus(422);
    $this->actingAs($this->admin)->deleteJson("/api/admin/v1/ai/budgets/{$id}")->assertNoContent();
});

it('shows platform usage, cost analytics and the request log', function () {
    routeTask('system.test', ['fake/fake-echo']);
    $a = createWorkspace(name: 'A');
    $b = createWorkspace(name: 'B');
    foreach ([$a, $b] as $i => $ws) {
        app(TenantContext::class)->runAs($ws, null, fn () => app(AiGateway::class)->execute(new AiTask('system.test', 'hello '.$i, 'u-'.$i)));
    }

    $usage = $this->actingAs($this->admin)->getJson('/api/admin/v1/ai/usage?days=7')->assertOk();
    expect($usage->json('data.totals.requests'))->toBe(2)
        ->and(collect($usage->json('data.by_workspace'))->pluck('workspace_name')->sort()->values()->all())->toBe(['A', 'B'])
        ->and($usage->json('data.by_provider.0.provider'))->toBe('fake');

    $this->actingAs($this->admin)->getJson("/api/admin/v1/ai/usage?workspace_id={$a->id}")->assertOk()->assertJsonPath('data.totals.requests', 1);

    $log = $this->actingAs($this->admin)->getJson('/api/admin/v1/ai/requests')->assertOk();
    expect($log->json('data'))->toHaveCount(2)->and($log->json('data.0'))->toHaveKeys(['provider', 'model', 'status', 'cost_micro_usd', 'usage.input_tokens', 'workspace.name'])
        ->and($log->json('data.0'))->not->toHaveKey('prompt');
});

it('monitors jobs and lets staff retry or discard dead letters', function () {
    routeTask('system.test', ['fake/fake-echo']);
    $ws = createWorkspace(name: 'Jobs Co');
    [$run] = app(TenantContext::class)->runAs($ws, null, fn () => app(WorkflowEngine::class)
        ->start('ai.test', ['task_key' => 'system.test', 'prompt' => '[fail-once:timeout:adm1] x', 'structured' => false, 'web_search' => false]));

    $overview = $this->actingAs($this->admin)->getJson('/api/admin/v1/jobs/overview')->assertOk();
    expect($overview->json('data.workflows.failed'))->toBe(1)->and($overview->json('data.dead_letters_open'))->toBe(1)
        ->and(collect($overview->json('data.queues'))->pluck('name'))->toContain('research', 'writing', 'factcheck', 'media', 'publishing', 'analytics', 'billing', 'notifications');

    $this->actingAs($this->admin)->getJson('/api/admin/v1/jobs/workflows?status=failed')->assertOk()->assertJsonPath('data.0.id', $run->id)
        ->assertJsonPath('data.0.workspace.name', 'Jobs Co');
    $this->actingAs($this->admin)->getJson("/api/admin/v1/jobs/workflows/{$run->id}")->assertOk()->assertJsonPath('data.status', 'failed');

    $dead = $this->actingAs($this->admin)->getJson('/api/admin/v1/jobs/dead-letters')->assertOk()->json('data.0');
    expect($dead['source'])->toBe('workflow');

    $this->actingAs($this->admin)->postJson("/api/admin/v1/jobs/dead-letters/{$dead['id']}/retry")->assertOk()->assertJsonPath('data.status', 'retried');
    expect(app(TenantContext::class)->runAs($ws, null, fn () => WorkflowRun::query()->findOrFail($run->id)->status->value))->toBe('succeeded');

    $other = new DeadLetter;
    $other->forceFill(['source' => 'job', 'job_class' => 'X', 'queue' => 'default', 'error_code' => 'job_failed', 'attempts' => 1, 'status' => 'open'])->save();
    $this->actingAs($this->admin)->postJson("/api/admin/v1/jobs/dead-letters/{$other->id}/discard")->assertOk()->assertJsonPath('data.status', 'discarded');
});

it('lets staff cancel a queued run from the job monitor', function () {
    Queue::fake();
    $ws = createWorkspace();
    [$run] = app(TenantContext::class)->runAs($ws, null, fn () => app(WorkflowEngine::class)
        ->start('ai.test', ['task_key' => 'system.test', 'prompt' => 'queued', 'structured' => false, 'web_search' => false]));

    $this->actingAs($this->admin)->postJson("/api/admin/v1/jobs/workflows/{$run->id}/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');
    $this->actingAs($this->admin)->postJson('/api/admin/v1/jobs/workflows/not-a-ulid/cancel')->assertNotFound();
});
