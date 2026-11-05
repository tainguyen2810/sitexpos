<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Gateway;

use Illuminate\Support\Collection;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Providers\ProviderRegistry;

/**
 * Picks the ordered list of candidate models for a task:
 * primary → fallbacks (by route order), or economy first when a budget is
 * past its alert threshold. Disabled models/providers and models lacking a
 * required capability are removed.
 */
final class TaskRouter
{
    public function __construct(
        private readonly BudgetManager $budgets,
        private readonly ProviderRegistry $registry,
    ) {}

    /**
     * @return array{models: list<AiModel>, downgraded: bool}
     */
    public function candidates(AiTaskRoute $route, AiTask $task, ?string $workspaceId): array
    {
        $ids = array_values(array_filter([$route->primary_model_id, ...($route->fallback_model_ids ?? [])], 'is_string'));
        $downgraded = false;

        if ($route->economy_model_id !== null && $this->budgets->isNearLimit($workspaceId)) {
            $ids = [$route->economy_model_id, ...array_values(array_diff($ids, [$route->economy_model_id]))];
            $downgraded = $ids[0] !== $route->primary_model_id;
        }

        /** @var Collection<string, AiModel> $models */
        $models = AiModel::query()->with('provider')->whereIn('id', array_unique($ids))->get()->keyBy('id');

        $ordered = [];
        foreach (array_unique($ids) as $id) {
            $model = $models->get($id);
            if ($model !== null && $this->eligible($model, $task)) {
                $ordered[] = $model;
            }
        }

        return ['models' => $ordered, 'downgraded' => $downgraded];
    }

    /**
     * Super Admin model test: exactly one model, enabled flags ignored (a model
     * is tested BEFORE it is switched on), capability checks still apply.
     *
     * @return array{models: list<AiModel>, downgraded: bool}
     */
    public function forced(string $modelId, AiTask $task): array
    {
        $model = AiModel::query()->with('provider')->find($modelId);

        return ['models' => $model !== null && $this->eligible($model, $task, ignoreEnabled: true) ? [$model] : [], 'downgraded' => false];
    }

    public function eligible(AiModel $model, AiTask $task, bool $ignoreEnabled = false): bool
    {
        if (! $this->registry->has($model->provider->driver)) {
            return false;
        }
        if (! $ignoreEnabled && (! $model->is_enabled || ! $model->provider->is_enabled)) {
            return false;
        }
        if ($task->kind === 'image') {
            return $model->supports('image_generation');
        }
        if ($model->kind !== 'text') {
            return false;
        }
        if ($task->schema !== null && ! $model->supports('structured_output')) {
            return false;
        }

        return ! ($task->webSearch && ! $model->supports('web_search'));
    }
}
