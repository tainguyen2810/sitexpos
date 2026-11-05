<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workflows\Domain\DeadLetter;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\WorkflowEngine;
use SiteXPost\Modules\Workflows\Enums\WorkflowStatus;
use SiteXPost\Modules\Workflows\Http\Resources\DeadLetterResource;
use SiteXPost\Modules\Workflows\Http\Resources\WorkflowRunResource;
use SiteXPost\Modules\Workflows\Support\Queues;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use Throwable;

/**
 * Super Admin job monitor: queues, workflow runs of every tenant, dead
 * letters with manual retry/discard. Cross-tenant reads use reviewed scope
 * bypasses; tenant writes (retry/cancel) run inside that tenant's context.
 */
final class AdminJobController
{
    public function __construct(private readonly TenantContext $context, private readonly WorkflowEngine $engine) {}

    public function overview(): JsonResponse
    {
        $queues = [];
        foreach (Queues::ALL as $name) {
            try {
                $queues[] = ['name' => $name, 'size' => Queue::size($name)];
            } catch (Throwable) {
                $queues[] = ['name' => $name, 'size' => null];
            }
        }

        $horizon = 'unknown';
        try {
            $masters = app(MasterSupervisorRepository::class)->all();
            $horizon = $masters === [] ? 'inactive' : (collect($masters)->contains(fn ($m) => ($m->status ?? '') === 'paused') ? 'paused' : 'running');
        } catch (Throwable) {
            $horizon = 'unavailable';
        }

        $counts = $this->context->bypass(fn () => WorkflowRun::query()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'));

        return new JsonResponse(['data' => [
            'queue_connection' => config('queue.default'),
            'horizon' => $horizon,
            'queues' => $queues,
            'workflows' => collect(WorkflowStatus::cases())->mapWithKeys(fn (WorkflowStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all(),
            'stuck' => $this->context->bypass(fn () => WorkflowRun::query()->where('status', WorkflowStatus::Running->value)->where('locked_until', '<', now())->count()),
            'dead_letters_open' => DeadLetter::query()->where('status', 'open')->count(),
            'failed_jobs' => (int) DB::table('failed_jobs')->count(),
        ]]);
    }

    public function workflows(Request $request): JsonResponse
    {
        return $this->context->bypass(function () use ($request): JsonResponse {
            $query = WorkflowRun::query()->with('workspace:id,name');
            $status = $request->query('status');
            if (is_string($status) && WorkflowStatus::tryFrom($status) !== null) {
                $query->where('status', $status);
            }
            $type = $request->query('type');
            if (is_string($type) && preg_match('/^[a-z0-9_.]{1,64}$/', $type) === 1) {
                $query->where('type', $type);
            }
            $ws = $request->query('workspace_id');
            if (is_string($ws) && Str::isUlid($ws)) {
                $query->where('workspace_id', strtolower($ws));
            }

            return Paginated::response($query->orderByDesc('created_at')->orderByDesc('id'), WorkflowRunResource::class, $request, 30);
        });
    }

    public function show(Request $request, string $run): JsonResponse
    {
        $model = $this->find($run);
        $this->context->bypass(fn () => $model->load(['steps', 'events', 'workspace:id,name']));

        return new JsonResponse(['data' => [
            ...(new WorkflowRunResource($model))->resolve($request),
            'workspace' => ['id' => $model->workspace_id, 'name' => $model->workspace?->name],
        ]]);
    }

    public function retry(Request $request, string $run): JsonResponse
    {
        $model = $this->find($run);
        $ok = $this->asTenant($model, fn () => $this->engine->retry(WorkflowRun::query()->findOrFail($model->id), (string) $request->user()?->getAuthIdentifier()));
        if (! $ok) {
            throw ApiException::conflict('invalid_state_transition', __('workflows.errors.not_retryable'));
        }
        Audit::record('platform.workflow.retried', $model, workspaceId: $model->workspace_id, actorType: 'platform_staff');

        return $this->show($request, $run);
    }

    public function cancel(Request $request, string $run): JsonResponse
    {
        $model = $this->find($run);
        $ok = $this->asTenant($model, fn () => $this->engine->cancel(WorkflowRun::query()->findOrFail($model->id), (string) $request->user()?->getAuthIdentifier()));
        if (! $ok) {
            throw ApiException::conflict('invalid_state_transition', __('workflows.errors.not_cancelable'));
        }
        Audit::record('platform.workflow.canceled', $model, workspaceId: $model->workspace_id, actorType: 'platform_staff');

        return $this->show($request, $run);
    }

    public function deadLetters(Request $request): JsonResponse
    {
        $query = DeadLetter::query();
        $status = $request->query('status', 'open');
        if (is_string($status) && in_array($status, ['open', 'retried', 'discarded'], true)) {
            $query->where('status', $status);
        }

        return Paginated::response($query->orderByDesc('created_at')->orderByDesc('id'), DeadLetterResource::class, $request, 30);
    }

    public function retryDeadLetter(Request $request, string $deadLetter): JsonResponse
    {
        $dead = $this->findDead($deadLetter);
        $userId = (string) $request->user()?->getAuthIdentifier();

        if ($dead->source === 'workflow' && $dead->workflow_run_id !== null) {
            $run = $this->find($dead->workflow_run_id);
            $ok = $this->asTenant($run, fn () => $this->engine->retry(WorkflowRun::query()->findOrFail($run->id), $userId));
            if (! $ok) {
                throw ApiException::conflict('invalid_state_transition', __('workflows.errors.not_retryable'));
            }
        } elseif ($dead->failed_job_uuid !== null) {
            if (Artisan::call('queue:retry', ['id' => [$dead->failed_job_uuid]]) !== 0) {
                throw ApiException::conflict('retry_failed', 'The failed job could not be re-queued.');
            }
        }

        $dead->forceFill(['status' => 'retried', 'resolved_by' => $userId, 'resolved_at' => now()])->save();
        Audit::record('platform.dead_letter.retried', $dead, ['source' => $dead->source], workspaceId: $dead->workspace_id, actorType: 'platform_staff');

        return new JsonResponse(['data' => (new DeadLetterResource($dead->refresh()))->resolve($request)]);
    }

    public function discardDeadLetter(Request $request, string $deadLetter): JsonResponse
    {
        $dead = $this->findDead($deadLetter);
        $dead->forceFill(['status' => 'discarded', 'resolved_by' => (string) $request->user()?->getAuthIdentifier(), 'resolved_at' => now()])->save();
        Audit::record('platform.dead_letter.discarded', $dead, ['source' => $dead->source], workspaceId: $dead->workspace_id, actorType: 'platform_staff');

        return new JsonResponse(['data' => (new DeadLetterResource($dead))->resolve($request)]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asTenant(WorkflowRun $run, callable $callback): mixed
    {
        $workspace = Workspace::query()->find($run->workspace_id) ?? throw ApiException::notFound();

        return $this->context->runAs($workspace, null, $callback);
    }

    private function find(string $id): WorkflowRun
    {
        if (! Str::isUlid($id)) {
            throw ApiException::notFound();
        }

        return $this->context->bypass(fn () => WorkflowRun::query()->find(strtolower($id))) ?? throw ApiException::notFound();
    }

    private function findDead(string $id): DeadLetter
    {
        $dead = Str::isUlid($id) ? DeadLetter::query()->find(strtolower($id)) : null;
        if ($dead === null) {
            throw ApiException::notFound();
        }
        if ($dead->status !== 'open') {
            throw ApiException::conflict('invalid_state_transition', __('api.invalid_state_transition'));
        }

        return $dead;
    }
}
