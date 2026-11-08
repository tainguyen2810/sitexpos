<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Gateway;

use Illuminate\Support\Collection;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Providers\ProviderRegistry;
use Throwable;

/**
 * v1.0.13 — tier-based model selection (ADR-031).
 *
 * A task route names a level (`tier`: premium | standard | economy), not a
 * list of models. The models tried, in order, are:
 *   1. the pinned model, if the Super Admin chose one (optional);
 *   2. every model of the route's level, then the nearest levels — cheaper
 *      first, then stronger (standard → economy → premium, premium →
 *      standard → economy, economy → standard → premium);
 *   inside one level: provider priority, then model key. The offline "fake"
 *   provider is always last.
 * Switching a model on/off on the Providers page therefore flows into every
 * route; nothing has to be re-edited. Enabled flags and capabilities are
 * checked by TaskRouter::eligible (runtime) and resolve() (admin view).
 */
final class ModelSelector
{
    public const TIERS = ['premium', 'standard', 'economy'];

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly CircuitBreaker $breaker,
    ) {}

    /**
     * @return list<string>
     */
    public static function tierOrder(string $tier): array
    {
        return match ($tier) {
            'premium' => ['premium', 'standard', 'economy'],
            'economy' => ['economy', 'standard', 'premium'],
            default => ['standard', 'economy', 'premium'],
        };
    }

    /**
     * Every model of the route's kind, in try order (not filtered by
     * enabled flags or capabilities). $economyFirst: a budget is past its
     * alert threshold — cheapest level first, pinned model after it.
     *
     * @param  Collection<int, AiModel>|null  $all  preloaded models (with provider)
     * @return list<AiModel>
     */
    public function ordered(AiTaskRoute $route, bool $economyFirst = false, ?Collection $all = null): array
    {
        $all ??= AiModel::query()->with('provider')->get();
        $kind = $route->kind === 'image' ? 'image' : 'text';
        $models = $all->filter(fn (AiModel $m): bool => $m->kind === $kind && $m->provider !== null)
            ->sortBy(fn (AiModel $m): string => sprintf('%06d|%s', $m->provider->priority, $m->model_key))
            ->values();
        $real = $models->filter(fn (AiModel $m): bool => $m->provider->code !== 'fake');
        $fake = $models->filter(fn (AiModel $m): bool => $m->provider->code === 'fake');
        $pinned = $route->pinned_model_id !== null ? $models->firstWhere('id', $route->pinned_model_id) : null;

        $out = [];
        $levels = self::tierOrder($economyFirst ? 'economy' : (string) $route->tier);
        if (! $economyFirst && $pinned !== null) {
            $out[] = $pinned;
        }
        foreach ($levels as $i => $level) {
            foreach ($real->where('tier', $level) as $m) {
                $out[] = $m;
            }
            if ($economyFirst && $i === 0 && $pinned !== null) {
                $out[] = $pinned;
            }
        }
        foreach ($real->whereNotIn('tier', self::TIERS) as $m) {
            $out[] = $m;
        }
        foreach ($levels as $level) {
            foreach ($fake->where('tier', $level) as $m) {
                $out[] = $m;
            }
        }
        foreach ($fake->whereNotIn('tier', self::TIERS) as $m) {
            $out[] = $m;
        }

        $seen = [];

        return array_values(array_filter($out, function (AiModel $m) use (&$seen): bool {
            if (isset($seen[$m->id])) {
                return false;
            }
            $seen[$m->id] = true;

            return true;
        }));
    }

    /**
     * @return list<string>
     */
    public function orderedIds(AiTaskRoute $route): array
    {
        return array_map(fn (AiModel $m): string => $m->id, $this->ordered($route));
    }

    /**
     * Models that would run right now, in try order (fake provider included
     * only when it is configured, i.e. dev/test).
     *
     * @return list<AiModel>
     */
    public function usable(AiTaskRoute $route, bool $webSearch = false): array
    {
        return array_values(array_filter($this->ordered($route), fn (AiModel $m): bool => $this->skipReason($m, $route, $webSearch) === null));
    }

    /**
     * Worst-case-relevant models for a cost estimate: the usable models of
     * the level that would answer first (a pinned model counts on its own).
     *
     * @return list<AiModel>
     */
    public function estimateModels(AiTaskRoute $route, bool $webSearch = false): array
    {
        $usable = $this->usable($route, $webSearch);
        $first = $usable[0] ?? null;
        if ($first === null) {
            return [];
        }

        return array_values(array_filter($usable, fn (AiModel $m): bool => $m->id === $first->id
            || ($m->tier === $first->tier && $m->provider->code !== 'fake' && $first->provider->code !== 'fake')));
    }

    /**
     * Why a model would be skipped for this route right now (null = it would run).
     */
    public function skipReason(AiModel $model, AiTaskRoute $route, bool $webSearch = false): ?string
    {
        $provider = $model->provider;
        if (! $provider->is_enabled) {
            return 'provider_off';
        }
        if (! $this->configured($provider->driver, $provider)) {
            return 'no_key';
        }
        if (! $model->is_enabled) {
            return 'model_off';
        }
        if ($route->kind === 'image' ? ! $model->supports('image_generation') : $model->kind !== 'text') {
            return 'wrong_kind';
        }
        if (($webSearch || $route->requires_web_search) && ! $model->supports('web_search')) {
            return 'no_web_search';
        }
        if ($model->priceAt() === null) {
            return 'no_price';
        }
        try {
            if ($this->breaker->state($provider)['state'] === 'open') {
                return 'circuit_open';
            }
        } catch (Throwable) {
            // cache unavailable: the gateway decides at call time
        }

        return null;
    }

    /**
     * Admin view of a route: which model really runs now and why.
     *
     * status: off (route disabled) | exact (pinned model, or a model of the
     * chosen level) | substitute (another level stands in) | blocked.
     * blocked_reason: needs_web_search (models exist but none can search the
     * web) | no_model.
     *
     * @param  Collection<int, AiModel>|null  $all
     * @return array{status: string, runs_on: array<string, mixed>|null, blocked_reason: string|null, pinned_reason: string|null, candidates: list<array<string, mixed>>}
     */
    public function resolve(AiTaskRoute $route, ?Collection $all = null, bool $includeFake = false): array
    {
        $runs = null;
        $pinnedReason = null;
        $candidates = [];
        $lackSearch = false;
        foreach ($this->ordered($route, false, $all) as $m) {
            if ($m->provider->code === 'fake' && ! $includeFake) {
                continue;
            }
            $reason = $this->skipReason($m, $route);
            if ($m->id === $route->pinned_model_id) {
                $pinnedReason = $reason;
            }
            if ($reason === 'no_web_search') {
                $lackSearch = true;
            }
            $item = ['id' => $m->id, 'model_key' => $m->model_key, 'display_name' => $m->display_name, 'tier' => $m->tier,
                'provider' => $m->provider->name, 'reason' => $reason, 'pinned' => $m->id === $route->pinned_model_id];
            $candidates[] = $item;
            if ($reason === null && $runs === null) {
                $runs = $item;
            }
        }

        $status = match (true) {
            ! $route->is_enabled => 'off',
            $runs === null => 'blocked',
            $route->pinned_model_id !== null ? $runs['pinned'] : $runs['tier'] === $route->tier => 'exact',
            default => 'substitute',
        };

        return [
            'status' => $status,
            'runs_on' => $runs,
            'blocked_reason' => $runs === null ? ($lackSearch ? 'needs_web_search' : 'no_model') : null,
            'pinned_reason' => $pinnedReason,
            'candidates' => $candidates,
        ];
    }

    private function configured(string $driver, AiProvider $provider): bool
    {
        if (! $this->registry->has($driver)) {
            return false;
        }
        try {
            return $this->registry->for($provider)->isConfigured();
        } catch (Throwable) {
            return false;
        }
    }
}
