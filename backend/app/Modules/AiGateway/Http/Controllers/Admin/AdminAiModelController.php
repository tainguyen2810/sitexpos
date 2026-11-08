<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Logging\SecretRedactor;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiModelPrice;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Domain\AiRequest;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Gateway\AiGateway;
use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\AiGateway\Gateway\AiTask;
use SiteXPost\Modules\AiGateway\Http\Requests\StoreAiModelRequest;
use SiteXPost\Modules\AiGateway\Http\Requests\StoreAiPriceRequest;
use SiteXPost\Modules\AiGateway\Http\Requests\TestAiModelRequest;
use SiteXPost\Modules\Audit\Audit;

/**
 * Model registry + versioned price list. Price versions are immutable once
 * in effect: a change is a NEW version with its own effective date, so past
 * requests keep the price they were billed with (ai_requests.price_id).
 */
final class AdminAiModelController
{
    public const TEST_TASK = 'system.model_test';

    private const TEST_PROMPT = 'Xin chào! 2 + 2 bằng mấy?';

    private const TEST_SCHEMA = [
        'type' => 'object',
        'properties' => ['answer' => ['type' => 'string'], 'confident' => ['type' => 'boolean']],
        'required' => ['answer', 'confident'],
        'additionalProperties' => false,
    ];

    public function index(Request $request): JsonResponse
    {
        $query = AiModel::query()->with(['provider', 'prices' => fn ($q) => $q->orderByDesc('effective_from')])->orderBy('model_key');
        if (is_string($request->query('provider_id')) && Str::isUlid($request->query('provider_id'))) {
            $query->where('provider_id', $request->query('provider_id'));
        }

        return new JsonResponse(['data' => $query->get()->map(fn (AiModel $m): array => $this->present($m))->all()]);
    }

    public function store(StoreAiModelRequest $request): JsonResponse
    {
        $provider = AiProvider::query()->findOrFail($request->string('provider_id')->toString());
        $name = $request->string('model_name')->toString();
        if (AiModel::query()->where('model_key', $provider->code.'/'.$name)->exists()) {
            throw ApiException::conflict('model_exists', 'Model already registered.');
        }

        $model = new AiModel;
        $model->forceFill([
            'provider_id' => $provider->id,
            'model_key' => $provider->code.'/'.$name,
            'model_name' => $name,
            'display_name' => $request->string('display_name')->toString(),
            'kind' => $request->input('kind', 'text'),
            'tier' => $request->input('tier', 'standard'),
            'capabilities' => $this->capabilities([], (array) $request->input('capabilities', [])),
            // New models start disabled until a price version exists.
            'is_enabled' => false,
            'notes' => $request->input('notes'),
        ])->save();
        Audit::record('platform.ai.model.created', $model, ['model_key' => $model->model_key], actorType: 'platform_staff');

        return new JsonResponse(['data' => $this->present($model->load(['provider', 'prices']))], 201);
    }

    public function update(StoreAiModelRequest $request, string $model): JsonResponse
    {
        $row = $this->find($model);
        $data = $request->validated();
        $newName = $data['model_name'] ?? null;
        unset($data['model_name']);
        if (isset($data['capabilities'])) {
            $data['capabilities'] = $this->capabilities($row->capabilities, (array) $data['capabilities']);
        }
        if (($data['is_enabled'] ?? false) === true && $row->priceAt(now()->addYears(10)) === null) {
            throw ApiException::conflict('price_missing', 'Add a price version before enabling this model.');
        }
        $before = $row->only(array_keys($data));
        if (is_string($newName) && $newName !== $row->model_name) {
            // Changing the Model ID: past ai_requests keep the old name (audit trail).
            $key = $row->provider->code.'/'.$newName;
            if (AiModel::query()->where('model_key', $key)->whereKeyNot($row->id)->exists()) {
                throw ApiException::conflict('model_exists', 'Model already registered.');
            }
            $before['model_name'] = $row->model_name;
            $data['model_name'] = $newName;
            $row->forceFill(['model_name' => $newName, 'model_key' => $key]);
        }
        $row->fill(collect($data)->except('model_name')->all())->save();
        Audit::record('platform.ai.model.updated', $row, ['before' => $before, 'after' => $data], actorType: 'platform_staff');

        return new JsonResponse(['data' => $this->present($row->load(['provider', 'prices']))]);
    }

    public function prices(string $model): JsonResponse
    {
        $row = $this->find($model);

        return new JsonResponse(['data' => $row->prices()->orderByDesc('effective_from')->orderByDesc('version')->get()
            ->map(fn (AiModelPrice $p): array => $this->presentPrice($p, $row))->all()]);
    }

    public function storePrice(StoreAiPriceRequest $request, string $model): JsonResponse
    {
        $row = $this->find($model);

        $price = DB::transaction(function () use ($request, $row): AiModelPrice {
            $version = (int) AiModelPrice::query()->where('model_id', $row->id)->lockForUpdate()->max('version') + 1;
            $price = new AiModelPrice;
            $price->forceFill([
                ...collect($request->validated())->only(AiModelPrice::RATE_FIELDS)->all(),
                'model_id' => $row->id,
                'version' => $version,
                'effective_from' => Carbon::parse($request->string('effective_from')->toString())->utc(),
                'currency' => 'USD',
                'source_url' => $request->input('source_url'),
                'notes' => $request->input('notes'),
                'created_by' => (string) $request->user()?->getAuthIdentifier(),
            ])->save();

            return $price;
        });
        Audit::record('platform.ai.price.created', $row, ['version' => $price->version, 'effective_from' => $price->effective_from->toIso8601ZuluString(),
            'input_per_mtok' => $price->input_per_mtok, 'output_per_mtok' => $price->output_per_mtok], actorType: 'platform_staff');

        return new JsonResponse(['data' => $this->presentPrice($price, $row)], 201);
    }

    /**
     * A model can be deleted only if it was never called and no route uses it;
     * otherwise disable it (history and billing keep pointing at it).
     */
    public function destroy(string $model, TenantContext $context): JsonResponse
    {
        $row = $this->find($model);
        $used = $context->bypass(fn (): bool => AiRequest::query()->where('model_id', $row->id)->exists());
        $routed = AiTaskRoute::query()->get()->contains(fn (AiTaskRoute $r): bool => $r->pinned_model_id === $row->id || $r->primary_model_id === $row->id
            || $r->economy_model_id === $row->id || in_array($row->id, $r->fallback_model_ids ?? [], true));
        if ($used || $routed) {
            throw ApiException::conflict('model_in_use', __('ai.errors.model_in_use'));
        }
        DB::transaction(function () use ($row): void {
            $row->prices()->delete();
            $row->delete();
        });
        Audit::record('platform.ai.model.deleted', $row, ['model_key' => $row->model_key], actorType: 'platform_staff');

        return new JsonResponse(null, 204);
    }

    /**
     * Test Model: one REAL (paid, tiny) call to exactly this model through the
     * normal gateway path — budget reservation (global budget), cost from the
     * price version, ai_requests row, no automatic paid retry. Works on a
     * disabled model so it can be checked before it is switched on.
     */
    public function test(TestAiModelRequest $request, string $model, AiGateway $gateway): JsonResponse
    {
        $row = $this->find($model);
        $structured = $request->boolean('structured');
        $prompt = trim((string) $request->input('prompt', '')) ?: self::TEST_PROMPT;
        $started = hrtime(true);

        try {
            $result = $gateway->execute(new AiTask(
                taskKey: self::TEST_TASK,
                prompt: $prompt,
                callKey: 'admin-test:'.Str::lower((string) Str::ulid()),
                system: 'You are a connectivity test for SiteX Post. Answer in one short sentence, in the language of the question.',
                schema: $structured ? self::TEST_SCHEMA : null,
                schemaName: 'model_test',
                maxOutputTokens: 200,
                modelId: $row->id,
                platform: true,
            ));
            $data = [
                'ok' => true,
                'text' => mb_substr($result->text, 0, 2000),
                'json' => $result->json,
                'usage' => $result->usage->toArray(),
                'cost_micro_usd' => $result->costMicroUsd,
                'finish_reason' => $result->finishReason,
                'request_id' => $result->requestId,
            ];
        } catch (AiGatewayException $e) {
            $data = [
                'ok' => false,
                'error_code' => $e->errorCode,
                'error_message' => SecretRedactor::redact($e->getMessage()),
                'attempts' => $e->details['attempts'] ?? [],
            ];
        }
        $data['latency_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);
        $data['model_key'] = $row->model_key;
        Audit::record('platform.ai.model.tested', $row, ['ok' => $data['ok'], 'error_code' => $data['error_code'] ?? null, 'cost_micro_usd' => $data['cost_micro_usd'] ?? 0], actorType: 'platform_staff');

        return new JsonResponse(['data' => $data]);
    }

    /**
     * Only a scheduled (not yet effective) version can be removed.
     */
    public function destroyPrice(string $model, string $price): JsonResponse
    {
        $row = $this->find($model);
        $version = Str::isUlid($price) ? AiModelPrice::query()->where('model_id', $row->id)->find($price) : null;
        if ($version === null) {
            throw ApiException::notFound();
        }
        if ($version->effective_from->lte(now())) {
            throw ApiException::conflict('price_version_locked', __('ai.errors.price_version_locked'));
        }
        $version->delete();
        Audit::record('platform.ai.price.deleted', $row, ['version' => $version->version], actorType: 'platform_staff');

        return new JsonResponse(null, 204);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function capabilities(array $current, array $changes): array
    {
        $defaults = array_fill_keys(AiModel::CAPABILITIES, false) + ['context_window' => null, 'max_output_tokens' => null, 'reasoning_effort' => null];

        return array_intersect_key(array_merge($defaults, $current, $changes), $defaults);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiModel $m): array
    {
        $current = $m->prices->first(fn (AiModelPrice $p): bool => $p->effective_from->lte(now()));

        return [
            'id' => $m->id,
            'provider' => ['id' => $m->provider->id, 'code' => $m->provider->code, 'name' => $m->provider->name],
            'model_key' => $m->model_key,
            'model_name' => $m->model_name,
            'display_name' => $m->display_name,
            'kind' => $m->kind,
            'tier' => $m->tier,
            'capabilities' => $m->capabilities,
            'is_enabled' => $m->is_enabled,
            'notes' => $m->notes,
            'current_price' => $current !== null ? $this->presentPrice($current, $m) : null,
            'scheduled_prices' => $m->prices->filter(fn (AiModelPrice $p): bool => $p->effective_from->isFuture())->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPrice(AiModelPrice $p, AiModel $m): array
    {
        return [
            'id' => $p->id,
            'version' => $p->version,
            'effective_from' => $p->effective_from->toIso8601ZuluString(),
            'state' => $p->effective_from->isFuture() ? 'scheduled' : 'effective',
            'currency' => $p->currency,
            ...collect($p->only(AiModelPrice::RATE_FIELDS))->all(),
            'source_url' => $p->source_url,
            'notes' => $p->notes,
            'created_at' => $p->created_at->toIso8601ZuluString(),
        ];
    }

    private function find(string $id): AiModel
    {
        if (! Str::isUlid($id)) {
            throw ApiException::notFound();
        }

        return AiModel::query()->with('provider')->find($id) ?? throw ApiException::notFound();
    }
}
