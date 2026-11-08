<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Gateway;

use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Providers\ProviderRegistry;

/**
 * Picks the ordered list of candidate models for a task from the route's
 * level (ModelSelector), cheapest level first when a budget is past its
 * alert threshold. Disabled models/providers and models lacking a required
 * capability are removed.
 */
final class TaskRouter
{
    public function __construct(
        private readonly BudgetManager $budgets,
        private readonly ProviderRegistry $registry,
        private readonly ModelSelector $selector,
    ) {}

    /**
     * @return array{models: list<AiModel>, downgraded: bool}
     */
    public function candidates(AiTaskRoute $route, AiTask $task, ?string $workspaceId): array
    {
        $all = AiModel::query()->with('provider')->get();
        $normal = $this->filter($this->selector->ordered($route, false, $all), $task);
        if (! $this->budgets->isNearLimit($workspaceId)) {
            return ['models' => $normal, 'downgraded' => false];
        }
        $cheap = $this->filter($this->selector->ordered($route, true, $all), $task);

        return ['models' => $cheap, 'downgraded' => ($cheap[0] ?? null)?->id !== ($normal[0] ?? null)?->id];
    }

    /**
     * @param  list<AiModel>  $models
     * @return list<AiModel>
     */
    private function filter(array $models, AiTask $task): array
    {
        $eligible = array_values(array_filter($models, fn (AiModel $m): bool => $this->eligible($m, $task)));
        // A route spans every provider of its level: drop providers without a
        // key up front so they are not reported as failed fallbacks. If none
        // is configured, keep them so the gateway reports provider_not_configured.
        $configured = array_values(array_filter($eligible, fn (AiModel $m): bool => $this->registry->for($m->provider)->isConfigured()));

        return $configured !== [] ? $configured : $eligible;
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
