<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Support;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\AiGateway\Contracts\AiReadiness;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Gateway\CircuitBreaker;
use SiteXPost\Modules\AiGateway\Gateway\ModelSelector;
use Throwable;

/**
 * Phase 9 — AiReadiness implementation (Setup Wizard, System Health).
 * The offline "fake" provider never counts as ready.
 */
final class AiReadinessReport implements AiReadiness
{
    public function __construct(
        private readonly CircuitBreaker $breaker,
        private readonly ModelSelector $selector,
    ) {}

    public function providers(): array
    {
        return AiProvider::query()->orderBy('priority')->orderBy('code')->get()->map(function (AiProvider $p): array {
            $circuit = 'unknown';
            try {
                $circuit = $this->breaker->state($p)['state'];
            } catch (Throwable) {
                // cache unavailable
            }
            $isFake = $p->code === 'fake';
            $hasKey = ! $isFake && $p->hasApiKey();

            return [
                'code' => $p->code,
                'name' => $p->name,
                'driver' => $p->driver,
                'enabled' => $p->is_enabled,
                'has_key' => $hasKey,
                'key_source' => $isFake ? 'none' : $p->apiKeySource(),
                'circuit' => $circuit,
                'ready' => ! $isFake && $p->is_enabled && $hasKey && $circuit !== 'open',
            ];
        })->values()->all();
    }

    public function routes(array $taskKeys): array
    {
        $routes = AiTaskRoute::query()->whereIn('task_key', $taskKeys)->get()->keyBy('task_key');
        $models = AiModel::query()->with('provider')->get();

        $out = [];
        foreach ($taskKeys as $key) {
            /** @var AiTaskRoute|null $route */
            $route = $routes->get($key);
            if ($route === null) {
                $out[] = ['task_key' => $key, 'configured' => false, 'enabled' => false, 'primary' => null, 'primary_kind' => null, 'ready_models' => [], 'ready' => false];

                continue;
            }
            $ordered = $this->selector->ordered($route, false, $models);
            $ready = [];
            foreach ($ordered as $m) {
                if ($m->is_enabled && $m->provider->is_enabled && $m->provider->code !== 'fake' && $m->provider->hasApiKey()
                    && ! ($route->requires_web_search && ! $m->supports('web_search'))) {
                    $ready[] = $m->model_key;
                }
            }
            $primary = $ordered[0] ?? null;
            $out[] = [
                'task_key' => $key,
                'configured' => true,
                'enabled' => $route->is_enabled,
                'primary' => $primary?->model_key,
                'primary_kind' => $route->kind,
                'ready_models' => array_values(array_unique($ready)),
                'ready' => $route->is_enabled && $ready !== [],
            ];
        }

        return $out;
    }

    public function spendSince(DateTimeInterface $since): int
    {
        return (int) DB::table('ai_requests')->where('created_at', '>=', $since)->sum('cost_micro_usd');
    }

    public function requestsSince(DateTimeInterface $since): array
    {
        $total = DB::table('ai_requests')->where('created_at', '>=', $since)->count();
        $failed = DB::table('ai_requests')->where('created_at', '>=', $since)->where('status', 'failed')->count();

        return ['total' => $total, 'failed' => $failed];
    }
}
