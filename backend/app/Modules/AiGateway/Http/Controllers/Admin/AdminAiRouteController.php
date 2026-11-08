<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Gateway\ModelSelector;
use SiteXPost\Modules\AiGateway\Http\Requests\UpdateAiRouteRequest;
use SiteXPost\Modules\Audit\Audit;

/**
 * Task routes (v1.0.13): each route names a model level and, optionally, a
 * pinned model. `resolved` says which model really runs now (ModelSelector).
 */
final class AdminAiRouteController
{
    public function __construct(private readonly ModelSelector $selector) {}

    public function index(): JsonResponse
    {
        $models = AiModel::query()->with('provider')->get();

        return new JsonResponse(['data' => AiTaskRoute::query()->orderBy('task_key')->get()
            ->map(fn (AiTaskRoute $r): array => $this->present($r, $models))->all()]);
    }

    public function update(UpdateAiRouteRequest $request, string $taskRoute): JsonResponse
    {
        $route = Str::isUlid($taskRoute) ? AiTaskRoute::query()->find($taskRoute) : null;
        if ($route === null) {
            throw ApiException::notFound();
        }

        $data = $request->validated();
        if (($data['pinned_model_id'] ?? null) !== null) {
            $pinned = AiModel::query()->findOrFail($data['pinned_model_id']);
            if (($route->kind === 'image') !== ($pinned->kind === 'image')) {
                throw ValidationException::withMessages(['pinned_model_id' => __('ai.errors.pinned_wrong_kind')]);
            }
        }
        $before = $route->only(array_keys($data));
        $route->fill($data)->save();
        Audit::record('platform.ai.route.updated', $route, ['task_key' => $route->task_key, 'before' => $before, 'after' => $data], actorType: 'platform_staff');

        return new JsonResponse(['data' => $this->present($route, AiModel::query()->with('provider')->get())]);
    }

    /**
     * @param  Collection<int, AiModel>  $models
     * @return array<string, mixed>
     */
    private function present(AiTaskRoute $r, Collection $models): array
    {
        $pinned = $r->pinned_model_id !== null ? $models->firstWhere('id', $r->pinned_model_id) : null;

        return [
            'id' => $r->id,
            'task_key' => $r->task_key,
            'description' => $r->description,
            'queue' => $r->queue,
            'priority' => $r->priority,
            'tier' => $r->tier,
            'kind' => $r->kind,
            'requires_web_search' => $r->requires_web_search,
            'pinned_model' => $pinned !== null
                ? ['id' => $pinned->id, 'model_key' => $pinned->model_key, 'display_name' => $pinned->display_name, 'is_enabled' => $pinned->is_enabled]
                : null,
            'resolved' => $this->selector->resolve($r, $models),
            'max_input_tokens' => $r->max_input_tokens,
            'max_output_tokens' => $r->max_output_tokens,
            'temperature' => $r->temperature !== null ? (float) $r->temperature : null,
            'timeout_seconds' => $r->timeout_seconds,
            'max_attempts' => $r->max_attempts,
            'allow_paid_retry' => $r->allow_paid_retry,
            'is_enabled' => $r->is_enabled,
            'updated_at' => $r->updated_at->toIso8601ZuluString(),
        ];
    }
}
