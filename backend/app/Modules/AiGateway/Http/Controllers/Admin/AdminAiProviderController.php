<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Gateway\CircuitBreaker;
use SiteXPost\Modules\AiGateway\Http\Requests\UpdateAiProviderRequest;
use SiteXPost\Modules\Audit\Audit;

/**
 * Provider administration. API keys are never readable or writable here:
 * only `has_api_key` (whether .env has one) is exposed.
 */
final class AdminAiProviderController
{
    public function __construct(private readonly CircuitBreaker $circuit) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => AiProvider::query()->withCount('models')->orderBy('priority')->get()
            ->map(fn (AiProvider $p): array => $this->present($p))->all()]);
    }

    public function update(UpdateAiProviderRequest $request, string $provider): JsonResponse
    {
        $model = $this->find($provider);
        $before = $model->only(array_keys($request->validated()));
        $model->fill($request->validated())->save();
        Audit::record('platform.ai.provider.updated', $model, ['before' => $before, 'after' => $request->validated()], actorType: 'platform_staff');

        return new JsonResponse(['data' => $this->present($model->loadCount('models'))]);
    }

    public function resetCircuit(string $provider): JsonResponse
    {
        $model = $this->find($provider);
        $this->circuit->reset($model);
        Audit::record('platform.ai.provider.circuit_reset', $model, actorType: 'platform_staff');

        return new JsonResponse(['data' => $this->present($model->loadCount('models'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiProvider $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'is_enabled' => $p->is_enabled,
            'has_api_key' => $p->hasApiKey(),
            'priority' => $p->priority,
            'timeout_seconds' => $p->timeout_seconds,
            'max_retries' => $p->max_retries,
            'circuit_failure_threshold' => $p->circuit_failure_threshold,
            'circuit_cooldown_seconds' => $p->circuit_cooldown_seconds,
            'circuit' => $this->circuit->state($p),
            'models_count' => (int) ($p->getAttribute('models_count') ?? 0),
            'updated_at' => $p->updated_at->toIso8601ZuluString(),
        ];
    }

    private function find(string $id): AiProvider
    {
        if (! Str::isUlid($id)) {
            throw ApiException::notFound();
        }

        return AiProvider::query()->find($id) ?? throw ApiException::notFound();
    }
}
