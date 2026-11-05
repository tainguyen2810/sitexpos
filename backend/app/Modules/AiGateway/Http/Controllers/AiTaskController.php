<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Http\Paginated;
use SiteXPost\Modules\AiGateway\Http\Requests\StoreAiTaskRequest;
use SiteXPost\Modules\AiGateway\Workflows\AiTestWorkflow;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\IdempotencyConflict;
use SiteXPost\Modules\Workflows\Engine\WorkflowEngine;
use SiteXPost\Modules\Workflows\Enums\WorkflowStatus;
use SiteXPost\Modules\Workflows\Http\Resources\WorkflowRunResource;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Background AI tasks of a workspace (Phase 3: the AI test task). Never runs
 * AI inside the HTTP request: returns 202 + workflow_run/job_run.
 */
final class AiTaskController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', WorkflowRun::class);

        $query = WorkflowRun::query();
        $status = $request->query('status');
        if (is_string($status) && WorkflowStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        return Paginated::response($query->orderByDesc('created_at')->orderByDesc('id'), WorkflowRunResource::class, $request, 20);
    }

    public function store(StoreAiTaskRequest $request, Workspace $workspace, WorkflowEngine $engine): JsonResponse
    {
        Gate::authorize('create', WorkflowRun::class);

        $key = $request->header('Idempotency-Key');
        if ($key !== null && preg_match('/^[A-Za-z0-9_\-:.]{8,120}$/', $key) !== 1) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), ['Idempotency-Key' => ['8–120 characters: letters, digits, _ - : .']]);
        }

        try {
            [$run, $created] = $engine->start(AiTestWorkflow::TYPE, [
                'task_key' => AiTestWorkflow::TASK_KEY,
                'prompt' => $request->string('prompt')->toString(),
                'structured' => $request->boolean('structured'),
                'web_search' => $request->boolean('web_search'),
            ], $key, (string) $request->user()?->getAuthIdentifier());
        } catch (IdempotencyConflict) {
            throw ApiException::conflict('idempotency_conflict', __('ai.errors.idempotency_conflict'));
        }

        if ($created) {
            Audit::record('ai.task.created', $run, ['type' => $run->type]);
        }
        $run->refresh();

        return new JsonResponse(['data' => [
            'workflow_run' => (new WorkflowRunResource($run))->resolve($request),
            'job_run' => ['id' => $run->job_run_id, 'status' => $run->status->value === 'succeeded' ? 'succeeded' : 'queued', 'type' => 'workflow.'.$run->type],
        ]], $created ? 202 : 200);
    }

    public function show(Request $request, Workspace $workspace, WorkflowRun $workflowRun): JsonResponse
    {
        Gate::authorize('view', $workflowRun);
        $workflowRun->load(['steps', 'events']);

        return new JsonResponse(['data' => (new WorkflowRunResource($workflowRun))->resolve($request)]);
    }

    public function cancel(Request $request, Workspace $workspace, WorkflowRun $workflowRun, WorkflowEngine $engine): JsonResponse
    {
        Gate::authorize('operate', $workflowRun);
        if (! $engine->cancel($workflowRun, (string) $request->user()?->getAuthIdentifier())) {
            throw ApiException::conflict('invalid_state_transition', __('workflows.errors.not_cancelable'));
        }
        Audit::record('ai.task.cancel_requested', $workflowRun);

        return $this->show($request, $workspace, $workflowRun->refresh());
    }

    public function retry(Request $request, Workspace $workspace, WorkflowRun $workflowRun, WorkflowEngine $engine): JsonResponse
    {
        Gate::authorize('operate', $workflowRun);
        if (! $engine->retry($workflowRun, (string) $request->user()?->getAuthIdentifier())) {
            throw ApiException::conflict('invalid_state_transition', __('workflows.errors.not_retryable'));
        }
        Audit::record('ai.task.retried', $workflowRun, ['cycle' => $workflowRun->cycle]);

        return $this->show($request, $workspace, $workflowRun->refresh())->setStatusCode(202);
    }
}
