<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Gateway;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Logging\SecretRedactor;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Contracts\UsageDenied;
use SiteXPost\Modules\AiGateway\Contracts\UsageMeter;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiModelPrice;
use SiteXPost\Modules\AiGateway\Domain\AiRequest;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Providers\ProviderException;
use SiteXPost\Modules\AiGateway\Providers\ProviderRegistry;
use SiteXPost\Modules\AiGateway\Providers\ProviderRequest;
use SiteXPost\Modules\AiGateway\Providers\ProviderResponse;
use SiteXPost\Modules\AiGateway\Providers\Usage;
use SiteXPost\Modules\Workflows\Engine\JobClock;
use SiteXPost\Modules\Workflows\Engine\StepYield;

/**
 * Single entry point for every AI call (ARCHITECTURE.md §8).
 *
 * For each candidate model (TaskRouter): circuit check → price lookup →
 * atomic budget reservation → ONE provider call with timeout → cost from the
 * versioned price → commit/release → append-only ai_requests row.
 *
 * Retry policy (never blind on paid calls):
 * - retryable + billing-safe errors (429, 5xx, connect failure) are retried
 *   on the same model up to provider.max_retries with exponential backoff,
 *   then the next candidate (fallback) is tried;
 * - billing-uncertain errors (response timeout after send) and paid failures
 *   (schema-invalid output) stop immediately unless the route sets
 *   allow_paid_retry; refusals and budget blocks stop immediately.
 */
final class AiGateway
{
    public function __construct(
        private readonly TaskRouter $router,
        private readonly ProviderRegistry $registry,
        private readonly CircuitBreaker $circuit,
        private readonly BudgetManager $budgets,
        private readonly CostCalculator $costs,
        private readonly JsonSchemaValidator $validator,
        private readonly TenantContext $context,
        private readonly UsageMeter $meter,
        private readonly JobClock $clock,
    ) {}

    /**
     * @throws AiGatewayException
     */
    public function execute(AiTask $task): AiResult
    {
        if ($task->platform) {
            // Super Admin model test: no tenant, global budget only.
            $workspaceId = null;
        } elseif (! $this->context->has()) {
            throw new AiGatewayException('tenant_required', 'AI calls must run inside a workspace context.');
        } else {
            $workspaceId = $this->context->workspaceId();
        }

        $route = AiTaskRoute::query()->where('task_key', $task->taskKey)->where('is_enabled', true)->first()
            ?? ($task->modelId !== null ? AiTaskRoute::transient($task->taskKey) : null)
            ?? throw new AiGatewayException('route_not_found', "No enabled route for task {$task->taskKey}.");

        $promptChars = mb_strlen(($task->system ?? '').$task->prompt);
        $promptTokens = (int) ceil($promptChars / max(1, (int) config('sitex.ai.chars_per_token', 3)));
        if ($promptChars > (int) config('sitex.ai.max_prompt_chars', 20000) || $promptTokens > $route->max_input_tokens) {
            throw new AiGatewayException('input_too_large', 'The prompt exceeds the limit configured for this task.');
        }

        ['models' => $models, 'downgraded' => $downgraded] = $task->modelId !== null
            ? $this->router->forced($task->modelId, $task)
            : $this->router->candidates($route, $task, $workspaceId);
        if ($models === []) {
            throw new AiGatewayException('no_model_available', 'No enabled model supports this task.');
        }

        $attempts = [];
        $attemptNo = 0;
        $last = null;
        // Whole-call deadline: retries and fallbacks must finish before the
        // queue job timeout (300 s), or the worker is killed mid-call.
        $deadline = microtime(true) + max(30, (int) config('sitex.ai.call_deadline_seconds', 240));
        // Inside a workflow job the job itself has a time budget (JobClock):
        // never start a provider call the worker would kill half-way.
        $jobDeadline = $this->clock->deadline();
        $promptHash = hash('sha256', ($task->system ?? '')."\n\n".$task->prompt);

        foreach ($models as $index => $model) {
            $provider = $model->provider;
            $adapter = $this->registry->for($provider);
            $isFallback = $index > 0;

            if (! $adapter->isConfigured()) {
                $attempts[] = ['model_key' => $model->model_key, 'status' => 'skipped', 'error_code' => 'provider_not_configured'];
                $last ??= new ProviderException('provider_not_configured', 'No configured provider is available.');

                continue;
            }
            if (! $this->circuit->allows($provider)) {
                $this->log($task, $model, null, $attemptNo + 1, $isFallback, 'skipped', $promptHash, error: new ProviderException('circuit_open', 'Circuit breaker open for '.$provider->code.'.'));
                $attempts[] = ['model_key' => $model->model_key, 'status' => 'skipped', 'error_code' => 'circuit_open'];
                $last = new ProviderException('circuit_open', 'All candidate providers are temporarily unavailable.', retryable: true);

                continue;
            }
            $price = $model->priceAt();
            if ($price === null) {
                $this->log($task, $model, null, $attemptNo + 1, $isFallback, 'skipped', $promptHash, error: new ProviderException('price_missing', 'No price configured for '.$model->model_key.'.'));
                $attempts[] = ['model_key' => $model->model_key, 'status' => 'skipped', 'error_code' => 'price_missing'];

                continue;
            }

            $maxOut = min($task->maxOutputTokens ?? $route->max_output_tokens, $route->max_output_tokens, $model->maxOutputTokens() ?? PHP_INT_MAX);
            $request = new ProviderRequest(
                model: $model->model_name,
                prompt: $task->prompt,
                system: $task->system,
                schema: $task->schema,
                schemaName: $task->schemaName,
                maxOutputTokens: max(1, $maxOut),
                temperature: $route->temperature !== null && $model->supports('temperature') ? (float) $route->temperature : null,
                webSearch: $task->webSearch,
                timeoutSeconds: $route->timeout_seconds ?? $provider->timeout_seconds,
                kind: $task->kind,
                imageCount: $task->imageCount,
                imageSize: $task->imageSize,
                reasoningEffort: $model->reasoningEffort(),
            );
            $estimate = $this->costs->estimate($price, $promptTokens, $request->maxOutputTokens, $task->webSearch ? $request->maxSearches : 0, $task->kind === 'image' ? $task->imageCount : 0);

            for ($try = 0; $try <= $provider->max_retries; $try++) {
                if ($jobDeadline !== null && microtime(true) + $request->timeoutSeconds > $jobDeadline && $this->clock->fitsFreshJob($request->timeoutSeconds)) {
                    // Nothing is sent and nothing is billed: the run continues in a new job.
                    throw new StepYield;
                }
                if (microtime(true) + $request->timeoutSeconds > $deadline) {
                    $attempts[] = ['model_key' => $model->model_key, 'status' => 'skipped', 'error_code' => 'deadline_exceeded'];
                    $last = new ProviderException('deadline_exceeded', 'No time left for another provider attempt.', retryable: true);

                    break 2;
                }
                $attemptNo++;
                $key = $task->callKey.':'.$attemptNo;

                // Commercial layer first (plan / credits, Phase 7), then the
                // platform cost budget. Both are keyed by the same call key.
                if ($workspaceId !== null) {
                    try {
                        $this->meter->reserve($key, $workspaceId, $task->taskKey, $estimate);
                    } catch (UsageDenied $e) {
                        $this->log($task, $model, $price, $attemptNo, $isFallback, 'blocked', $promptHash, error: new ProviderException($e->errorCode, $e->getMessage()), estimate: $estimate);
                        throw new AiGatewayException($e->errorCode, $e->getMessage(), details: $e->details);
                    }
                }
                try {
                    $reservation = $this->budgets->reserve($key, $workspaceId, $estimate, $route->priority);
                } catch (BudgetExceeded $e) {
                    $this->meter->release($key);
                    $this->log($task, $model, $price, $attemptNo, $isFallback, 'blocked', $promptHash, error: new ProviderException('budget_exceeded', $e->getMessage()), estimate: $estimate);
                    throw new AiGatewayException('budget_exceeded', __('ai.errors.budget_exceeded'), details: ['scope' => $e->scopeKey === 'global' ? 'global' : 'workspace', 'period' => $e->period]);
                }
                if (! $reservation->wasRecentlyCreated) {
                    // Same call key seen before: another worker made (or is making)
                    // this exact paid call. Never send it twice, never charge twice.
                    throw new AiGatewayException('duplicate_call', __('ai.errors.duplicate_call'));
                }

                $started = hrtime(true);
                $calledAt = now();
                try {
                    $response = $adapter->generate($request);
                } catch (ProviderException $e) {
                    $latency = (int) ((hrtime(true) - $started) / 1_000_000);
                    $cost = $e->usage !== null ? $this->costs->cost($price, $e->usage, $calledAt) : 0;
                    if ($e->billingUncertain) {
                        $this->budgets->commit($key, max($cost, $estimate), 'uncertain');
                        $this->meter->settle($key, max($cost, $estimate), 'uncertain');
                    } elseif ($cost > 0) {
                        $this->budgets->commit($key, $cost);
                        $this->meter->settle($key, $cost);
                    } else {
                        $this->budgets->release($key);
                        $this->meter->release($key);
                    }
                    if ($e->countsAsFailure) {
                        $this->circuit->recordFailure($provider);
                    }
                    $this->log($task, $model, $price, $attemptNo, $isFallback, 'failed', $promptHash, $e->usage, $cost, $latency, $e, $estimate);
                    $attempts[] = ['model_key' => $model->model_key, 'status' => 'failed', 'error_code' => $e->errorCode, 'billing_uncertain' => $e->billingUncertain];
                    $last = $e;

                    if ($e->errorCode === 'refused') {
                        throw new AiGatewayException('refused', __('ai.errors.refused'));
                    }
                    if ($e->billingUncertain) {
                        if (! $route->allow_paid_retry) {
                            throw new AiGatewayException($e->errorCode, __('ai.errors.billing_uncertain'), requiresPaidRetry: true, details: ['attempts' => $attempts]);
                        }
                        break; // paid retries allowed: try the next candidate, never the same call again
                    }
                    if ($e->retryable && $try < $provider->max_retries) {
                        $this->backoff($try, $e->retryAfter);

                        continue;
                    }
                    break;
                }

                $latency = (int) ((hrtime(true) - $started) / 1_000_000);

                return $this->complete($task, $route, $model, $price, $response, $key, $attemptNo, $isFallback, $downgraded, $promptHash, $latency, $attempts, $calledAt, $estimate, $request->maxOutputTokens);
            }
        }

        $code = $last->errorCode ?? 'no_model_available';
        $retryable = $last === null || $last->retryable || in_array($code, ['provider_not_configured', 'circuit_open'], true);

        throw new AiGatewayException($code, __('ai.errors.all_failed'), retryable: $retryable, details: ['attempts' => $attempts]);
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     */
    private function complete(AiTask $task, AiTaskRoute $route, AiModel $model, AiModelPrice $price, ProviderResponse $response, string $key, int $attemptNo, bool $isFallback, bool $downgraded, string $promptHash, int $latency, array $attempts, ?CarbonInterface $calledAt = null, int $estimate = 0, ?int $maxOutputTokens = null): AiResult
    {
        // A successful answer without any usage numbers (some custom/OpenAI-compatible
        // providers omit `usage`) must never be free: charge the reservation estimate
        // and flag the call as billing-uncertain so it shows up in cost reports.
        $usageMissing = self::usageMissing($response->usage);
        $cost = $usageMissing ? $estimate : $this->costs->cost($price, $response->usage, $calledAt);
        $this->budgets->commit($key, $cost, $usageMissing ? 'uncertain' : 'committed');
        $this->meter->settle($key, $cost, $usageMissing ? 'uncertain' : 'settled');
        if ($usageMissing) {
            Log::warning('ai.usage_missing', ['provider' => $model->provider->code, 'model' => $model->model_key, 'task' => $task->taskKey, 'charged_estimate_micro_usd' => $estimate]);
        }
        $this->circuit->recordSuccess($model->provider);

        $json = null;
        if ($task->schema !== null) {
            $json = json_decode($response->text, true);
            $errors = json_last_error() === JSON_ERROR_NONE ? $this->validator->validate($json, $task->schema) : ['$: not valid JSON'];
            if ($errors !== []) {
                // The most common cause is an answer cut off at max_tokens (half a
                // JSON document). Say so in the log and tell the caller, which may
                // retry once with a larger output budget under a new call key.
                $truncated = $response->finishReason === 'max_tokens';
                $error = new ProviderException('invalid_output', ($truncated ? 'Output truncated at max_tokens ('.($maxOutputTokens ?? '?').'); ' : '')
                    .'Model output does not match the schema: '.implode('; ', array_slice($errors, 0, 3)));
                $this->log($task, $model, $price, $attemptNo, $isFallback, 'failed', $promptHash, $response->usage, $cost, $latency, $error, finish: $response->finishReason, providerRequestId: $response->providerRequestId);
                throw new AiGatewayException('invalid_output', __('ai.errors.invalid_output'), requiresPaidRetry: true,
                    details: ['finish_reason' => $response->finishReason, 'max_output_tokens' => $maxOutputTokens]);
            }
        }

        $row = $this->log($task, $model, $price, $attemptNo, $isFallback, 'succeeded', $promptHash, $response->usage, $cost, $latency, estimate: $estimate, finish: $response->finishReason, providerRequestId: $response->providerRequestId, usageMissing: $usageMissing);
        $attempts[] = ['model_key' => $model->model_key, 'status' => 'succeeded'];

        return new AiResult(
            text: $response->text,
            json: $json,
            usage: $response->usage,
            costMicroUsd: $cost,
            provider: $model->provider->code,
            model: $model->model_name,
            modelKey: $model->model_key,
            requestId: $row->ulid,
            finishReason: $response->finishReason,
            usedFallback: $isFallback,
            downgraded: $downgraded,
            attempts: $attempts,
            images: $response->images,
        );
    }

    private static function usageMissing(Usage $usage): bool
    {
        return array_sum($usage->toArray()) === 0;
    }

    private function backoff(int $try, ?int $retryAfter): void
    {
        $max = max(1, (int) config('sitex.ai.max_backoff_seconds', 8));
        $ms = $retryAfter !== null ? min($retryAfter, $max) * 1000 : min($max * 1000, 500 * (2 ** $try) + random_int(0, 250));
        Sleep::for($ms)->milliseconds();
    }

    private function log(
        AiTask $task, AiModel $model, ?AiModelPrice $price, int $attempt, bool $isFallback, string $status, string $promptHash,
        ?Usage $usage = null, int $cost = 0, int $latency = 0, ?ProviderException $error = null, int $estimate = 0,
        ?string $finish = null, ?string $providerRequestId = null, bool $usageMissing = false,
    ): AiRequest {
        $row = new AiRequest;
        $row->forceFill([
            'task_key' => $task->taskKey,
            'provider' => $model->provider->code,
            'model' => $model->model_name,
            'model_id' => $model->id,
            'price_id' => $price?->id,
            'workflow_run_id' => $task->workflowRunId,
            'call_key' => mb_substr($task->callKey, 0, 160),
            'attempt' => min(255, $attempt),
            'is_fallback' => $isFallback,
            'status' => $status,
            'error_code' => $error?->errorCode,
            'error_message' => $error !== null ? SecretRedactor::redact($error->getMessage()) : null,
            'http_status' => $error?->httpStatus,
            'billing_uncertain' => ($error !== null && $error->billingUncertain) || $usageMissing,
            'input_tokens' => $usage->inputTokens ?? 0,
            'cached_input_tokens' => $usage->cachedInputTokens ?? 0,
            'cache_write_tokens' => $usage->cacheWriteTokens ?? 0,
            'output_tokens' => $usage->outputTokens ?? 0,
            'reasoning_tokens' => $usage->reasoningTokens ?? 0,
            'image_count' => $usage->images ?? 0,
            'search_count' => $usage->searches ?? 0,
            'cost_micro_usd' => $cost,
            'estimated_cost_micro_usd' => $estimate,
            'latency_ms' => $latency,
            'prompt_hash' => $promptHash,
            'provider_request_id' => $providerRequestId !== null ? mb_substr($providerRequestId, 0, 120) : null,
            'finish_reason' => $finish,
        ]);
        if ($task->platform) {
            // Platform-level row (workspace_id null): the tenant guard would
            // reject it, so it is written without model events. Reviewed path,
            // only reachable from the Super Admin model test.
            $row->setAttribute('ulid', strtolower((string) Str::ulid()));
            AiRequest::withoutEvents(fn (): bool => $row->save());
        } else {
            $row->save();
        }

        return $row;
    }
}
