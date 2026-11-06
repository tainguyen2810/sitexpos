<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Logging\SecretRedactor;
use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Security\SsrfViolation;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Gateway\CircuitBreaker;
use SiteXPost\Modules\AiGateway\Http\Requests\StoreAiProviderRequest;
use SiteXPost\Modules\AiGateway\Http\Requests\UpdateAiProviderRequest;
use SiteXPost\Modules\AiGateway\Providers\ProviderException;
use SiteXPost\Modules\AiGateway\Providers\ProviderRegistry;
use SiteXPost\Modules\Audit\Audit;

/**
 * Provider administration.
 *
 * API keys are WRITE-ONLY: stored encrypted (Crypt) and never returned; the
 * API exposes only has_api_key, api_key_last4 and api_key_source
 * (database | env | none). Custom providers use the openai_compatible driver
 * with an SSRF-checked https base URL. Built-in base URLs stay in config.
 */
final class AdminAiProviderController
{
    public function __construct(
        private readonly CircuitBreaker $circuit,
        private readonly ProviderRegistry $registry,
    ) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => AiProvider::query()->withCount('models')->orderBy('priority')->orderBy('name')->get()
            ->map(fn (AiProvider $p): array => $this->present($p))->all()]);
    }

    public function store(StoreAiProviderRequest $request): JsonResponse
    {
        $baseUrl = $this->safeBaseUrl($request->string('base_url')->toString());

        $provider = new AiProvider;
        $provider->forceFill([
            'code' => $request->string('code')->toString(),
            'driver' => 'openai_compatible',
            'name' => $request->string('name')->toString(),
            'base_url' => $baseUrl,
            'is_custom' => true,
            // Off until the admin has tested the connection.
            'is_enabled' => false,
            'priority' => $request->integer('priority', 100),
            'timeout_seconds' => $request->integer('timeout_seconds', 60),
            'max_retries' => $request->integer('max_retries', 1),
            'circuit_failure_threshold' => 5,
            'circuit_cooldown_seconds' => 60,
            'settings' => ['response_format' => $request->input('response_format', 'json_object')],
        ]);
        if ($request->filled('api_key')) {
            $provider->setApiKey($request->string('api_key')->toString());
        }
        $provider->save();
        Audit::record('platform.ai.provider.created', $provider, [
            'code' => $provider->code, 'base_url' => $baseUrl, 'api_key_set' => $provider->api_key_last4 !== null,
        ], actorType: 'platform_staff');

        return new JsonResponse(['data' => $this->present($provider->loadCount('models'))], 201);
    }

    public function update(UpdateAiProviderRequest $request, string $provider): JsonResponse
    {
        $model = $this->find($provider);
        $data = $request->validated();

        if ((isset($data['base_url']) || isset($data['response_format'])) && ! $model->is_custom) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), [
                'base_url' => [__('ai.errors.builtin_endpoint_locked')],
            ]);
        }

        $plain = collect($data)->only($model->getFillable())->all();
        $changes = ['before' => $model->only(array_keys($plain)), 'after' => $plain];
        $model->fill($plain);

        if (isset($data['base_url'])) {
            $changes['base_url'] = ['before' => $model->base_url, 'after' => $this->safeBaseUrl($data['base_url'])];
            $model->base_url = $changes['base_url']['after'];
        }
        if (isset($data['response_format'])) {
            $model->settings = [...($model->settings ?? []), 'response_format' => $data['response_format']];
            $changes['response_format'] = $data['response_format'];
        }
        if (($data['clear_api_key'] ?? false) === true) {
            $model->setApiKey(null);
            $changes['api_key'] = 'cleared';
        } elseif (is_string($data['api_key'] ?? null) && trim($data['api_key']) !== '') {
            $model->setApiKey($data['api_key']);
            // Only the fact that it changed and its last 4 characters are audited.
            $changes['api_key'] = 'replaced (…'.$model->api_key_last4.')';
        }
        $model->save();
        Audit::record('platform.ai.provider.updated', $model, $changes, actorType: 'platform_staff');

        return new JsonResponse(['data' => $this->present($model->loadCount('models'))]);
    }

    /**
     * Only custom providers without models can be deleted.
     */
    public function destroy(string $provider): JsonResponse
    {
        $model = $this->find($provider);
        if (! $model->is_custom) {
            throw ApiException::conflict('provider_builtin', __('ai.errors.provider_builtin'));
        }
        if ($model->models()->exists()) {
            throw ApiException::conflict('provider_in_use', __('ai.errors.provider_in_use'));
        }
        DB::transaction(fn () => $model->delete());
        Audit::record('platform.ai.provider.deleted', $model, ['code' => $model->code], actorType: 'platform_staff');

        return new JsonResponse(null, 204);
    }

    public function resetCircuit(string $provider): JsonResponse
    {
        $model = $this->find($provider);
        $this->circuit->reset($model);
        Audit::record('platform.ai.provider.circuit_reset', $model, actorType: 'platform_staff');

        return new JsonResponse(['data' => $this->present($model->loadCount('models'))]);
    }

    /**
     * Test Connection: one free model-list call. Reports reachability, key
     * validity, latency and whether each configured Model ID exists upstream.
     */
    public function testConnection(string $provider): JsonResponse
    {
        $model = $this->find($provider);
        $configured = $model->models()->orderBy('model_name')->pluck('model_name')->all();
        $adapter = $this->registry->has($model->driver) ? $this->registry->for($model) : null;

        $result = ['ok' => false, 'latency_ms' => null, 'error_code' => null, 'error_message' => null, 'models_found' => 0, 'models' => [], 'sample' => []];
        if ($adapter === null || ! $adapter->isConfigured()) {
            $result['error_code'] = 'provider_not_configured';
            $result['error_message'] = __('ai.errors.provider_not_configured');
        } else {
            $started = hrtime(true);
            try {
                $ids = $adapter->listModels(15);
                $result['ok'] = true;
                $result['models_found'] = count($ids);
                $result['sample'] = array_slice($ids, 0, 50);
                $result['models'] = array_map(fn (string $name): array => ['model_name' => $name, 'available' => in_array($name, $ids, true)], $configured);
            } catch (ProviderException $e) {
                $result['error_code'] = $e->errorCode;
                $result['error_message'] = SecretRedactor::redact($e->getMessage());
            }
            $result['latency_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);
        }
        Audit::record('platform.ai.provider.connection_tested', $model, ['ok' => $result['ok'], 'error_code' => $result['error_code']], actorType: 'platform_staff');

        return new JsonResponse(['data' => $result]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiProvider $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'driver' => $p->driver,
            'name' => $p->name,
            'is_custom' => $p->is_custom,
            'base_url' => $p->is_custom ? $p->base_url : null,
            'response_format' => $p->driver === 'openai_compatible' ? $p->responseFormat() : null,
            'is_enabled' => $p->is_enabled,
            'has_api_key' => $p->hasApiKey(),
            'api_key_last4' => $p->api_key_last4,
            'api_key_source' => $p->driver === 'fake' ? 'none' : $p->apiKeySource(),
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

    private function safeBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        try {
            app(SsrfGuard::class)->assertSafe($url);
        } catch (SsrfViolation $e) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), [
                'base_url' => [__('ai.errors.blocked_url', ['reason' => $e->reason])],
            ]);
        }
        if (parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new ApiException(422, 'validation_failed', __('api.validation_failed'), [
                'base_url' => [__('ai.errors.blocked_url', ['reason' => 'query_not_allowed'])],
            ]);
        }

        return $url;
    }

    private function find(string $id): AiProvider
    {
        if (! Str::isUlid($id)) {
            throw ApiException::notFound();
        }

        return AiProvider::query()->find($id) ?? throw ApiException::notFound();
    }
}
