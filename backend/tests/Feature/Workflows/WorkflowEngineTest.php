<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Domain\AiRequest;
use SiteXPost\Modules\AiGateway\Workflows\AiTestWorkflow;
use SiteXPost\Modules\Workflows\Domain\DeadLetter;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Domain\WorkflowStep;
use SiteXPost\Modules\Workflows\Engine\IdempotencyConflict;
use SiteXPost\Modules\Workflows\Engine\InvalidTransition;
use SiteXPost\Modules\Workflows\Engine\JobClock;
use SiteXPost\Modules\Workflows\Engine\WorkflowEngine;
use SiteXPost\Modules\Workflows\Engine\WorkflowStateMachine;
use SiteXPost\Modules\Workflows\Enums\StepStatus;
use SiteXPost\Modules\Workflows\Enums\WorkflowStatus;
use SiteXPost\Modules\Workflows\Jobs\ProcessWorkflow;
use SiteXPost\Modules\Workspace\Domain\Workspace;

beforeEach(function () {
    seedAi();
    Sleep::fake();
    routeTask('system.test', ['fake/fake-echo']);
    // No in-call retry on the same model: transient errors surface to the
    // workflow layer so its backoff can be tested.
    AiProvider::query()->where('code', 'fake')->update(['max_retries' => 0]);
    $this->ws = createWorkspace();
});

/**
 * @return array{0: WorkflowRun, 1: bool}
 */
function startTest(Workspace $ws, string $prompt, ?string $key = null, array $extra = []): array
{
    return app(TenantContext::class)->runAs($ws, null, fn () => app(WorkflowEngine::class)
        ->start(AiTestWorkflow::TYPE, ['task_key' => 'system.test', 'prompt' => $prompt, 'structured' => false, 'web_search' => false, ...$extra], $key));
}

function asTenant(Workspace $ws, Closure $fn): mixed
{
    return app(TenantContext::class)->runAs($ws, null, $fn);
}

function freshRun(Workspace $ws, string $id): WorkflowRun
{
    return asTenant($ws, fn () => WorkflowRun::query()->with('steps')->findOrFail($id));
}

function executeRun(Workspace $ws, string $id, string $token = 'worker-test'): void
{
    asTenant($ws, fn () => app(WorkflowEngine::class)->execute($id, $token));
}

it('runs an AI test workflow end to end and links cost to the run', function () {
    [$run, $created] = startTest($this->ws, 'Kiểm tra AI');

    $run = freshRun($this->ws, $run->id);
    $ai = asTenant($this->ws, fn () => AiRequest::query()->sole());
    $job = asTenant($this->ws, fn () => JobRun::query()->findOrFail($run->job_run_id));

    expect($created)->toBeTrue()
        ->and($run->status)->toBe(WorkflowStatus::Succeeded)
        ->and($run->steps->pluck('status')->unique()->all())->toBe([StepStatus::Succeeded])
        ->and($run->output['cost_micro_usd'])->toBe($ai->cost_micro_usd)
        ->and($run->output['usage']['input_tokens'] ?? null)->toBe($ai->input_tokens)
        ->and($ai->workflow_run_id)->toBe($run->id)
        ->and($ai->call_key)->toStartWith("wf:{$run->id}:generate:c1:a1")
        ->and($job->status)->toBe('succeeded')->and($job->progress)->toBe(100);
});

it('queues the work and returns immediately (HTTP never waits for AI)', function () {
    Queue::fake();

    [$run] = startTest($this->ws, 'background');

    Queue::assertPushedOn('writing', ProcessWorkflow::class, fn (ProcessWorkflow $job) => $job->runId === $run->id && $job->workspaceId === $this->ws->id);
    expect(freshRun($this->ws, $run->id)->status)->toBe(WorkflowStatus::Pending)
        ->and(asTenant($this->ws, fn () => AiRequest::query()->count()))->toBe(0);
});

it('returns the same run for the same idempotency key and rejects a different payload', function () {
    Queue::fake();
    [$a, $createdA] = startTest($this->ws, 'same', 'idem-key-123');
    [$b, $createdB] = startTest($this->ws, 'same', 'idem-key-123');

    expect($b->id)->toBe($a->id)->and($createdA)->toBeTrue()->and($createdB)->toBeFalse();
    Queue::assertPushed(ProcessWorkflow::class, 1);

    expect(fn () => startTest($this->ws, 'different', 'idem-key-123'))->toThrow(IdempotencyConflict::class);

    // keys are per workspace
    [$other, $createdOther] = startTest(createWorkspace(), 'same', 'idem-key-123');
    expect($createdOther)->toBeTrue()->and($other->id)->not->toBe($a->id);
});

it('retries a transient failure with exponential backoff, then succeeds', function () {
    [$run] = startTest($this->ws, '[fail-once:unavailable:wf1] hello');

    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::RetryScheduled)
        ->and($run->error_code)->toBe('provider_unavailable')
        ->and($run->next_attempt_at->diffInSeconds(now(), true))->toBeGreaterThanOrEqual(14);

    // the (sync) delayed job arrived early and was ignored; the real one runs later
    $this->travelTo($run->next_attempt_at->addSecond());
    executeRun($this->ws, $run->id);

    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::Succeeded)->and($run->attempts)->toBe(2)
        ->and(asTenant($this->ws, fn () => AiRequest::query()->pluck('status')->all()))->toBe(['failed', 'succeeded']);
});

it('fails after max attempts and writes a dead letter', function () {
    [$run] = startTest($this->ws, '[fail:unavailable] always down');

    for ($i = 0; $i < 5; $i++) {
        $run = freshRun($this->ws, $run->id);
        if ($run->status !== WorkflowStatus::RetryScheduled) {
            break;
        }
        $this->travelTo($run->next_attempt_at->addSecond());
        executeRun($this->ws, $run->id);
    }

    $run = freshRun($this->ws, $run->id);
    $dead = DeadLetter::query()->sole();
    expect($run->status)->toBe(WorkflowStatus::Failed)->and($run->attempts)->toBe(3)
        ->and($dead->workflow_run_id)->toBe($run->id)->and($dead->status)->toBe('open')->and($dead->error_code)->toBe('provider_unavailable');
});

it('does not retry a billing-uncertain paid call automatically', function () {
    [$run] = startTest($this->ws, '[fail:timeout] slow provider');

    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::Failed)
        ->and($run->error_code)->toBe('response_timeout')
        ->and($run->attempts)->toBe(1)
        ->and(asTenant($this->ws, fn () => AiRequest::query()->count()))->toBe(1)
        ->and(DeadLetter::query()->count())->toBe(1);
});

it('retries a billing-uncertain call when the route allows paid retries', function () {
    routeTask('system.test', ['fake/fake-echo'], ['allow_paid_retry' => true]);
    [$run] = startTest($this->ws, '[fail-once:timeout:wf2] slow once');

    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::RetryScheduled);
    $this->travelTo($run->next_attempt_at->addSecond());
    executeRun($this->ws, $run->id);

    expect(freshRun($this->ws, $run->id)->status)->toBe(WorkflowStatus::Succeeded);
});

it('cancels a queued run immediately and refuses to cancel a finished one', function () {
    Queue::fake();
    [$run] = startTest($this->ws, 'to cancel');

    expect(asTenant($this->ws, fn () => app(WorkflowEngine::class)->cancel(freshRun($this->ws, $run->id), null)))->toBeTrue();
    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::Canceled)
        ->and($run->steps->pluck('status')->unique()->all())->toBe([StepStatus::Canceled]);

    // a late job delivery does nothing
    executeRun($this->ws, $run->id);
    expect(freshRun($this->ws, $run->id)->status)->toBe(WorkflowStatus::Canceled)
        ->and(asTenant($this->ws, fn () => app(WorkflowEngine::class)->cancel(freshRun($this->ws, $run->id), null)))->toBeFalse();
});

it('stops a running run at the next step boundary when cancel is requested', function () {
    Queue::fake();
    [$run] = startTest($this->ws, 'running');
    // simulate: generate done, worker currently between steps
    asTenant($this->ws, function () use ($run) {
        WorkflowStep::query()->where('workflow_run_id', $run->id)->where('key', 'generate')
            ->update(['status' => 'succeeded', 'output' => json_encode(['cost_micro_usd' => 1])]);
        WorkflowRun::query()->whereKey($run->id)->update(['cancel_requested_at' => now()]);
    });

    executeRun($this->ws, $run->id);

    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::Canceled)
        ->and($run->steps->firstWhere('key', 'finalize')->status)->toBe(StepStatus::Canceled)
        ->and($run->steps->firstWhere('key', 'generate')->status)->toBe(StepStatus::Succeeded);
});

it('manually retries a failed run in a new cycle with new idempotency keys', function () {
    [$run] = startTest($this->ws, '[fail-once:timeout:wf3] fails then ok');
    expect(freshRun($this->ws, $run->id)->status)->toBe(WorkflowStatus::Failed);

    expect(asTenant($this->ws, fn () => app(WorkflowEngine::class)->retry(freshRun($this->ws, $run->id), 'staff-1')))->toBeTrue();

    $run = freshRun($this->ws, $run->id);
    $keys = asTenant($this->ws, fn () => AiRequest::query()->orderBy('id')->pluck('call_key')->all());
    expect($run->status)->toBe(WorkflowStatus::Succeeded)->and($run->cycle)->toBe(2)
        ->and($keys[0])->toContain(':c1:')->and($keys[1])->toContain(':c2:')
        ->and(DeadLetter::query()->sole()->status)->toBe('retried');

    expect(asTenant($this->ws, fn () => app(WorkflowEngine::class)->retry($run, null)))->toBeFalse(); // only failed runs
});

/**
 * Worker restart: the process was killed while a run was "running". Its
 * lease expires; the scheduler's recovery command re-dispatches it.
 */
it('recovers a run whose worker died between steps without repeating paid work', function () {
    Queue::fake();
    [$run] = startTest($this->ws, 'crash after generate');
    asTenant($this->ws, function () use ($run) {
        WorkflowStep::query()->where('workflow_run_id', $run->id)->where('key', 'generate')
            ->update(['status' => 'succeeded', 'output' => json_encode(['cost_micro_usd' => 42]), 'attempts' => 1]);
        WorkflowRun::query()->whereKey($run->id)->update([
            'status' => 'running', 'attempts' => 1, 'locked_by' => 'dead-worker', 'locked_until' => now()->subMinute(),
            'current_step' => 'finalize', 'updated_at' => now()->subMinutes(10), 'version' => 1,
        ]);
    });

    $this->artisan('sitex:workflows:recover')->expectsOutputToContain('Recovered 1')->assertSuccessful();
    Queue::assertPushed(ProcessWorkflow::class, 2); // initial + recovery

    executeRun($this->ws, $run->id, 'new-worker');

    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::Succeeded)
        ->and($run->output)->toBe(['cost_micro_usd' => 42]) // checkpoint reused
        ->and(asTenant($this->ws, fn () => AiRequest::query()->count()))->toBe(0); // no new AI call
    expect(asTenant($this->ws, fn () => $run->events()->pluck('event')->all()))->toContain('recovered', 'recovery_dispatched');
});

it('fails (not re-bills) a run whose worker died during a paid call', function () {
    Queue::fake();
    [$run] = startTest($this->ws, 'crash during generate');
    asTenant($this->ws, function () use ($run) {
        WorkflowStep::query()->where('workflow_run_id', $run->id)->where('key', 'generate')->update(['status' => 'running', 'attempts' => 1]);
        WorkflowRun::query()->whereKey($run->id)->update(['status' => 'running', 'attempts' => 1, 'locked_by' => 'dead', 'locked_until' => now()->subMinute(), 'version' => 1]);
    });

    executeRun($this->ws, $run->id);

    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::Failed)->and($run->error_code)->toBe('interrupted_paid_call')
        ->and(asTenant($this->ws, fn () => AiRequest::query()->count()))->toBe(0)
        ->and(DeadLetter::query()->sole()->error_code)->toBe('interrupted_paid_call');
});

it('re-runs an interrupted paid step when the route allows paid retries', function () {
    routeTask('system.test', ['fake/fake-echo'], ['allow_paid_retry' => true]);
    Queue::fake();
    [$run] = startTest($this->ws, 'crash during generate, paid retry ok');
    asTenant($this->ws, function () use ($run) {
        WorkflowStep::query()->where('workflow_run_id', $run->id)->where('key', 'generate')->update(['status' => 'running', 'attempts' => 1]);
        WorkflowRun::query()->whereKey($run->id)->update(['status' => 'running', 'attempts' => 1, 'locked_by' => 'dead', 'locked_until' => now()->subMinute(), 'version' => 1]);
    });

    executeRun($this->ws, $run->id);

    $run = freshRun($this->ws, $run->id);
    $ai = asTenant($this->ws, fn () => AiRequest::query()->sole());
    expect($run->status)->toBe(WorkflowStatus::Succeeded)->and($ai->call_key)->toEndWith(':a2'); // new key, not the crashed one
});

it('ignores a duplicate delivery while another worker holds the lease', function () {
    Queue::fake();
    [$run] = startTest($this->ws, 'leased');
    asTenant($this->ws, fn () => WorkflowRun::query()->whereKey($run->id)
        ->update(['status' => 'running', 'locked_by' => 'other', 'locked_until' => now()->addMinutes(5), 'version' => 1]));

    executeRun($this->ws, $run->id, 'me');

    expect(freshRun($this->ws, $run->id)->locked_by)->toBe('other')
        ->and(asTenant($this->ws, fn () => AiRequest::query()->count()))->toBe(0);
    expect(asTenant($this->ws, fn () => app(WorkflowEngine::class)->recoverable(freshRun($this->ws, $run->id))))->toBeFalse();
});

it('defers runs beyond the tenant concurrency limit', function () {
    config(['sitex.workflows.tenant_concurrency' => 1]);
    Queue::fake();
    [$run] = startTest($this->ws, 'limited');
    $slot = app(WorkflowEngine::class)->slot($this->ws->id); // another run holds the only slot

    executeRun($this->ws, $run->id);
    expect(freshRun($this->ws, $run->id)->status)->toBe(WorkflowStatus::Pending)
        ->and(freshRun($this->ws, $run->id)->next_attempt_at)->not->toBeNull();
    Queue::assertPushed(ProcessWorkflow::class, 2); // initial + deferred

    // other tenants are not affected
    [$other] = startTest($otherWs = createWorkspace(), 'other tenant');
    executeRun($otherWs, $other->id);
    expect(freshRun($otherWs, $other->id)->status)->toBe(WorkflowStatus::Succeeded);

    $slot->release();
    executeRun($this->ws, $run->id);
    expect(freshRun($this->ws, $run->id)->status)->toBe(WorkflowStatus::Succeeded);
});

it('rejects invalid state transitions and loses CAS races cleanly', function () {
    Queue::fake();
    [$run] = startTest($this->ws, 'cas');
    $machine = app(WorkflowStateMachine::class);

    asTenant($this->ws, function () use ($run, $machine) {
        $a = WorkflowRun::query()->findOrFail($run->id);
        $b = WorkflowRun::query()->findOrFail($run->id);

        expect($machine->transition($a, WorkflowStatus::Running, 'started'))->toBeTrue()
            ->and($machine->transition($b, WorkflowStatus::Running, 'started'))->toBeFalse() // stale version
            ->and($machine->transition($a, WorkflowStatus::Succeeded, 'succeeded'))->toBeTrue()
            ->and(fn () => $machine->transition($a, WorkflowStatus::Running, 'x'))->toThrow(InvalidTransition::class);
    });
});

it('records failed queue jobs as dead letters', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn(['data' => ['command' => 'O:8:"Foo":1:{s:11:"workspaceId";s:26:"'.$this->ws->id.'";}']]);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Example');
    $job->shouldReceive('getQueue')->andReturn('connector');
    $job->shouldReceive('uuid')->andReturn('0b6b3c39-0000-4000-8000-000000000001');
    $job->shouldReceive('attempts')->andReturn(3);
    $job->shouldIgnoreMissing();

    event(new JobFailed('redis', $job, new RuntimeException('boom key=secret-value')));

    $dead = DeadLetter::query()->sole();
    expect($dead->source)->toBe('job')->and($dead->workspace_id)->toBe($this->ws->id)
        ->and($dead->queue)->toBe('connector')->and($dead->error_message)->not->toContain('secret-value');
});

it('prunes old finished runs', function () {
    [$run] = startTest($this->ws, 'old');
    $this->travel(100)->days();
    $this->artisan('sitex:workflows:prune')->assertSuccessful();
    expect(asTenant($this->ws, fn () => WorkflowRun::query()->count()))->toBe(0);
});

/*
 * 1.0.14 — one queue job has a time budget (JobClock). A run never gets
 * killed mid paid call: it yields and continues in a new job.
 */
function almostOutOfTime(float $secondsLeft = 5): void
{
    app()->instance(JobClock::class, new class($secondsLeft) extends JobClock
    {
        public function __construct(private readonly float $left) {}

        public function start(int $budgetSeconds, ?float $startedAt = null): void
        {
            parent::start($budgetSeconds, microtime(true) - $budgetSeconds + $this->left);
        }
    });
}

it('does not start a paid call that cannot finish in this job: the run continues in a new job', function () {
    routeTask('system.test', ['fake/fake-echo'], ['timeout_seconds' => 60]);
    Queue::fake();
    [$run] = startTest($this->ws, 'long job');
    almostOutOfTime(5);

    executeRun($this->ws, $run->id);

    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::Pending)
        ->and($run->attempts)->toBe(0) // a yield is not a failed attempt
        ->and($run->locked_by)->toBeNull()
        ->and($run->steps->firstWhere('key', 'generate')->status)->toBe(StepStatus::Pending)
        ->and(asTenant($this->ws, fn () => AiRequest::query()->count()))->toBe(0) // nothing sent, nothing billed
        ->and(asTenant($this->ws, fn () => $run->events()->where('event', 'yielded')->count()))->toBe(1)
        ->and(DeadLetter::query()->count())->toBe(0);
    Queue::assertPushed(ProcessWorkflow::class, 2); // start + continuation

    app()->instance(JobClock::class, new JobClock); // the new job has a full budget
    executeRun($this->ws, $run->id);

    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::Succeeded)
        ->and(asTenant($this->ws, fn () => AiRequest::query()->sole())->call_key)->toEndWith(':a2');
});

it('yields between steps when the job budget is low, keeping finished steps as checkpoints', function () {
    config(['sitex.workflows.min_step_seconds' => 100000]);
    Queue::fake();
    [$run] = startTest($this->ws, 'two steps');

    executeRun($this->ws, $run->id);

    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::Pending)
        ->and($run->steps->firstWhere('key', 'generate')->status)->toBe(StepStatus::Succeeded)
        ->and($run->steps->firstWhere('key', 'finalize')->status)->toBe(StepStatus::Pending);

    executeRun($this->ws, $run->id); // first step of a new job always runs

    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::Succeeded)
        ->and(asTenant($this->ws, fn () => AiRequest::query()->count()))->toBe(1); // the paid step ran once
});

it('stops a run that keeps yielding without progress', function () {
    config(['sitex.workflows.max_yields' => 2]);
    routeTask('system.test', ['fake/fake-echo'], ['timeout_seconds' => 60]);
    Queue::fake();
    [$run] = startTest($this->ws, 'never enough time');
    almostOutOfTime(5);

    executeRun($this->ws, $run->id);
    executeRun($this->ws, $run->id);
    executeRun($this->ws, $run->id);

    $run = freshRun($this->ws, $run->id);
    expect($run->status)->toBe(WorkflowStatus::Failed)->and($run->error_code)->toBe('too_many_yields')
        ->and(asTenant($this->ws, fn () => AiRequest::query()->count()))->toBe(0);
});

it('still fails normally when a single call could never fit in one job (no endless yielding)', function () {
    routeTask('system.test', ['fake/fake-echo'], ['timeout_seconds' => 290]);
    Queue::fake();
    [$run] = startTest($this->ws, 'too slow for any job');

    executeRun($this->ws, $run->id);

    $run = freshRun($this->ws, $run->id);
    expect(asTenant($this->ws, fn () => $run->events()->where('event', 'yielded')->count()))->toBe(0)
        ->and($run->status)->not->toBe(WorkflowStatus::Pending);
});
