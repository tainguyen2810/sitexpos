<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\Audit\Domain\AuditLog;
use SiteXPost\Modules\Workflows\Jobs\ProcessWorkflow;

beforeEach(function () {
    seedAi();
    Sleep::fake();
    routeTask('system.test', ['fake/fake-echo']);
    AiProvider::query()->where('code', 'fake')->update(['max_retries' => 0]);
    $this->t = workspaceWithRoles('AI Co');
    $this->base = "/api/v1/workspaces/{$this->t['workspace']->id}/ai";
});

it('accepts an AI test task with 202 and does not run it in the request', function () {
    Queue::fake();

    $res = $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => 'Viết một câu chào', 'structured' => true]);

    $res->assertStatus(202)
        ->assertJsonPath('data.workflow_run.status', 'pending')
        ->assertJsonPath('data.workflow_run.type', 'ai.test')
        ->assertJsonPath('data.workflow_run.queue', 'writing')
        ->assertJsonPath('data.job_run.status', 'queued');
    Queue::assertPushedOn('writing', ProcessWorkflow::class);
    expect(AuditLog::query()->where('action', 'ai.task.created')->count())->toBe(1);
});

it('runs the task in the background and exposes result, tokens and cost', function () {
    // sync queue: the job runs right after the response is built
    $id = $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => 'Xin chào'])->json('data.workflow_run.id');

    $show = $this->actingAs($this->t['viewer'])->getJson("{$this->base}/tasks/{$id}")->assertOk();
    $show->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.steps.0.key', 'generate')
        ->assertJsonPath('data.output.provider', 'fake');
    expect($show->json('data.output.cost_micro_usd'))->toBeGreaterThan(0)
        ->and($show->json('data.output.usage.output_tokens'))->toBeGreaterThan(0);

    $usage = $this->actingAs($this->t['viewer'])->getJson("{$this->base}/usage")->assertOk();
    expect($usage->json('data.totals.requests'))->toBe(1)
        ->and($usage->json('data.totals.cost_micro_usd'))->toBe($show->json('data.output.cost_micro_usd'))
        ->and($usage->json('data.by_task.0.task_key'))->toBe('system.test');

    $this->actingAs($this->t['viewer'])->getJson("{$this->base}/tasks")->assertOk()->assertJsonPath('data.0.id', $id);
});

it('honours the Idempotency-Key header', function () {
    Queue::fake();
    $headers = ['Idempotency-Key' => 'client-req-0001'];

    $first = $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => 'one'], $headers)->assertStatus(202);
    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => 'one'], $headers)
        ->assertOk()->assertJsonPath('data.workflow_run.id', $first->json('data.workflow_run.id'));
    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => 'two'], $headers)
        ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');
    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => 'one'], ['Idempotency-Key' => 'bad key!'])
        ->assertStatus(422);

    Queue::assertPushed(ProcessWorkflow::class, 1);
});

it('enforces roles: viewers read, editors run', function () {
    Queue::fake();
    $this->actingAs($this->t['viewer'])->postJson("{$this->base}/tasks", ['prompt' => 'nope'])->assertForbidden();
    $this->actingAs($this->t['viewer'])->getJson("{$this->base}/tasks")->assertOk();
    $this->actingAs($this->t['admin'])->postJson("{$this->base}/tasks", ['prompt' => 'ok'])->assertStatus(202);
});

it('validates the payload and forbids tenant ids in the body', function () {
    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => ''])->assertStatus(422);
    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => str_repeat('a', 4001)])->assertStatus(422);
    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => 'x x', 'workspace_id' => createWorkspace()->id])->assertStatus(422);
});

it('cancels and manually retries tasks through the API', function () {
    Queue::fake();
    $id = $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => 'cancel me'])->json('data.workflow_run.id');

    $this->actingAs($this->t['viewer'])->postJson("{$this->base}/tasks/{$id}/cancel")->assertForbidden();
    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');
    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks/{$id}/cancel")->assertStatus(409);
    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks/{$id}/retry")->assertStatus(409); // only failed runs
});

it('reports a failed task with a clear error and lets the user retry it', function () {
    $id = $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => '[fail-once:timeout:api1] slow'])->json('data.workflow_run.id');

    $this->actingAs($this->t['editor'])->getJson("{$this->base}/tasks/{$id}")
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error.code', 'response_timeout');

    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks/{$id}/retry")->assertStatus(202);
    $this->actingAs($this->t['editor'])->getJson("{$this->base}/tasks/{$id}")->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.cycle', 2);
});

it('rate limits task submissions per user and workspace', function () {
    Queue::fake();
    config(['sitex.rate_limits.ai_tasks_per_minute' => 2]);

    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => 'a1'])->assertStatus(202);
    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => 'a2'])->assertStatus(202);
    $this->actingAs($this->t['editor'])->postJson("{$this->base}/tasks", ['prompt' => 'a3'])->assertStatus(429);
});
