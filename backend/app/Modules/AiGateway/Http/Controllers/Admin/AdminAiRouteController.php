<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Http\Requests\UpdateAiRouteRequest;
use SiteXPost\Modules\Audit\Audit;

final class AdminAiRouteController
{
    public function index(): JsonResponse
    {
        $models = AiModel::query()->get(['id', 'model_key', 'display_name', 'is_enabled'])->keyBy('id');

        return new JsonResponse(['data' => AiTaskRoute::query()->orderBy('task_key')->get()
            ->map(fn (AiTaskRoute $r): array => $this->present($r, $models->all()))->all()]);
    }

    public function update(UpdateAiRouteRequest $request, string $taskRoute): JsonResponse
    {
        $route = Str::isUlid($taskRoute) ? AiTaskRoute::query()->find($taskRoute) : null;
        if ($route === null) {
            throw ApiException::notFound();
        }

        $data = $request->validated();
        if (isset($data['fallback_model_ids'])) {
            $data['fallback_model_ids'] = array_values(array_diff($data['fallback_model_ids'], [$data['primary_model_id'] ?? $route->primary_model_id]));
        }
        $before = $route->only(array_keys($data));
        $route->fill($data)->save();
        Audit::record('platform.ai.route.updated', $route, ['task_key' => $route->task_key, 'before' => $before, 'after' => $data], actorType: 'platform_staff');

        $models = AiModel::query()->get(['id', 'model_key', 'display_name', 'is_enabled'])->keyBy('id');

        return new JsonResponse(['data' => $this->present($route, $models->all())]);
    }

    /**
     * @param  array<string, AiModel>  $models
     * @return array<string, mixed>
     */
    private function present(AiTaskRoute $r, array $models): array
    {
        $ref = static fn (?string $id): ?array => $id !== null && isset($models[$id])
            ? ['id' => $id, 'model_key' => $models[$id]->model_key, 'display_name' => $models[$id]->display_name, 'is_enabled' => $models[$id]->is_enabled]
            : null;

        return [
            'id' => $r->id,
            'task_key' => $r->task_key,
            'description' => $r->description,
            'queue' => $r->queue,
            'priority' => $r->priority,
            'primary_model' => $ref($r->primary_model_id),
            'economy_model' => $ref($r->economy_model_id),
            'fallback_models' => array_values(array_filter(array_map($ref, $r->fallback_model_ids ?? []))),
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
