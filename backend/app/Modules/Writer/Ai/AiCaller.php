<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Ai;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiRequest;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Gateway\AiGateway;
use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\AiGateway\Gateway\AiResult;
use SiteXPost\Modules\AiGateway\Gateway\AiTask;
use SiteXPost\Modules\AiGateway\Gateway\CostCalculator;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleAiUsage;

/**
 * Every writer AI call goes through here → Phase 3 AI Gateway (routing,
 * fallback, workspace/global budgets, circuit breaker, ai_requests log).
 * On top of the gateway this adds the ARTICLE hard cap and the per-article
 * cost attribution:
 * - before the call: worst-case estimate (primary + fallbacks) vs the
 *   article's remaining budget → BudgetBlocked, nothing is sent;
 * - one `article_ai_usage` row per call key (unique) → a duplicate delivery
 *   or a retry with the same key is never counted twice;
 * - the cost added to the article is what the gateway logged for that call
 *   key (provider-reported usage), or the estimate when billing is uncertain.
 */
class AiCaller
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly CostCalculator $costs,
    ) {}

    /**
     * @param  array<string, mixed>|null  $schema
     *
     * @throws BudgetBlocked
     * @throws AiGatewayException
     */
    public function call(Article $article, string $step, string $taskKey, string $prompt, ?array $schema, string $schemaName, string $callKey,
        ?string $workflowRunId, int $maxOut, ?string $system = null, bool $webSearch = false): AiResult
    {
        $estimate = $this->estimate($taskKey, mb_strlen(($system ?? '').$prompt), $maxOut, $webSearch);
        $article->refresh();
        if ($article->spent_micro_usd + $estimate > $article->budget_limit_micro_usd) {
            throw new BudgetBlocked($estimate, $article->remainingBudget());
        }

        $usage = $this->open($article, $step, $taskKey, $callKey, $workflowRunId, $estimate);

        try {
            $result = $this->gateway->execute(new AiTask(
                taskKey: $taskKey, prompt: $prompt, callKey: $callKey, system: $system, schema: $schema,
                schemaName: $schemaName, webSearch: $webSearch, maxOutputTokens: $maxOut, workflowRunId: $workflowRunId,
            ));
        } catch (AiGatewayException $e) {
            $this->settle($article, $usage, $callKey, null, $e->errorCode, $e->requiresPaidRetry, $estimate);
            if (self::truncated($e, $maxOut) && ! str_ends_with($callKey, self::LARGER)) {
                // The answer was cut off at max_tokens: sending the same request
                // again would fail the same way. One retry with twice the output
                // budget, under its own call key (a new, separately counted call).
                return $this->call($article, $step, $taskKey, $prompt, $schema, $schemaName, $callKey.self::LARGER, $workflowRunId,
                    min(self::MAX_OUTPUT_CAP, $maxOut * 2), $system, $webSearch);
            }
            throw $e;
        }
        $this->settle($article, $usage, $callKey, $result, null, false, $estimate);

        return $result;
    }

    private const LARGER = ':x2';

    private const MAX_OUTPUT_CAP = 16000;

    /**
     * Invalid output because the model hit the output limit we asked for
     * (not a lower cap of the route/model, where a bigger request changes nothing).
     */
    private static function truncated(AiGatewayException $e, int $maxOut): bool
    {
        return $e->errorCode === 'invalid_output'
            && ($e->details['finish_reason'] ?? null) === 'max_tokens'
            && ($e->details['max_output_tokens'] ?? null) === $maxOut
            && $maxOut < self::MAX_OUTPUT_CAP;
    }

    /**
     * Worst-case cost of one call on this route (highest-priced candidate).
     */
    public function estimate(string $taskKey, int $promptChars, int $maxOut, bool $webSearch = false): int
    {
        $route = AiTaskRoute::query()->where('task_key', $taskKey)->where('is_enabled', true)->first();
        if ($route === null) {
            return 0;
        }
        $ids = array_values(array_filter([$route->primary_model_id, $route->economy_model_id, ...($route->fallback_model_ids ?? [])], 'is_string'));
        $tokens = (int) ceil($promptChars / max(1, (int) config('sitex.ai.chars_per_token', 3)));
        $out = min($maxOut, (int) $route->max_output_tokens);
        $max = 0;
        foreach (AiModel::query()->whereIn('id', $ids)->where('is_enabled', true)->get() as $model) {
            $price = $model->priceAt();
            if ($price !== null) {
                $max = max($max, $this->costs->estimate($price, $tokens, $out, $webSearch ? 1 : 0));
            }
        }

        return $max;
    }

    /**
     * Whether a call with web search can be routed for this task: the route
     * is on and at least one enabled model of an enabled provider on it
     * declares the `web_search` capability (otherwise the gateway would
     * reject the call with no_model_available).
     */
    public function canWebSearch(string $taskKey): bool
    {
        $route = AiTaskRoute::query()->where('task_key', $taskKey)->where('is_enabled', true)->first();
        if ($route === null) {
            return false;
        }
        $ids = array_values(array_filter([$route->primary_model_id, $route->economy_model_id, ...($route->fallback_model_ids ?? [])], 'is_string'));

        return AiModel::query()->with('provider')->whereIn('id', $ids)->where('is_enabled', true)->get()
            ->contains(fn (AiModel $m) => $m->provider !== null && $m->provider->is_enabled && $m->supports('web_search'));
    }

    private function open(Article $article, string $step, string $taskKey, string $callKey, ?string $runId, int $estimate): ArticleAiUsage
    {
        $existing = ArticleAiUsage::query()->where('call_key', $callKey)->first();
        if ($existing !== null) {
            return $existing;
        }
        $row = new ArticleAiUsage;
        $row->forceFill([
            'article_id' => $article->id, 'site_id' => $article->site_id, 'campaign_id' => $article->campaign_id,
            'workflow_run_id' => $runId, 'step' => mb_substr($step, 0, 40), 'task_key' => $taskKey, 'call_key' => mb_substr($callKey, 0, 190),
            'status' => 'started', 'estimated_cost_micro_usd' => $estimate,
        ]);
        try {
            $row->save();
        } catch (QueryException) {
            return ArticleAiUsage::query()->where('call_key', $callKey)->firstOrFail();
        }

        return $row;
    }

    private function settle(Article $article, ArticleAiUsage $usage, string $callKey, ?AiResult $result, ?string $error, bool $uncertain, int $estimate): void
    {
        if ($usage->status !== 'started') {
            return; // already settled by an earlier delivery: never count twice
        }
        $logs = AiRequest::query()->where('call_key', mb_substr($callKey, 0, 160));
        $logged = (int) (clone $logs)->sum('cost_micro_usd');
        // A failed call can still have been billed (e.g. output cut off at max_tokens):
        // report the tokens the gateway logged instead of 0/0.
        $inTokens = $result !== null ? $result->usage->inputTokens : (int) (clone $logs)->sum('input_tokens');
        $outTokens = $result !== null ? $result->usage->outputTokens : (int) (clone $logs)->sum('output_tokens');
        $cost = $uncertain ? max($logged, $estimate) : $logged;
        if ($result !== null) {
            $cost = max($cost, $result->costMicroUsd);
        }

        DB::transaction(function () use ($article, $usage, $result, $error, $cost, $inTokens, $outTokens): void {
            $claimed = ArticleAiUsage::query()->whereKey($usage->id)->where('status', 'started')->update([
                'status' => $result !== null ? 'succeeded' : 'failed',
                'provider' => $result?->provider,
                'model' => $result?->model,
                'ai_request_id' => $result?->requestId,
                'input_tokens' => $inTokens,
                'output_tokens' => $outTokens,
                'usage_reported' => ($inTokens + $outTokens) > 0,
                'cost_micro_usd' => $cost,
                'retries' => $result !== null ? max(0, count($result->attempts) - 1) : 0,
                'error_code' => $error,
                'updated_at' => now(),
            ]);
            if ($claimed > 0 && $cost > 0) {
                Article::query()->whereKey($article->id)->increment('spent_micro_usd', $cost);
            }
        });
        $article->refresh();
    }

    /**
     * Retrieval work (no AI cost) — recorded for the cost report.
     */
    public function recordFetches(Article $article, string $callKey, ?string $runId, int $fetches): void
    {
        if ($fetches <= 0 || ArticleAiUsage::query()->where('call_key', $callKey)->exists()) {
            return;
        }
        $row = new ArticleAiUsage;
        $row->forceFill([
            'article_id' => $article->id, 'site_id' => $article->site_id, 'campaign_id' => $article->campaign_id, 'workflow_run_id' => $runId,
            'step' => 'retrieve', 'task_key' => 'research.fetch', 'call_key' => mb_substr($callKey, 0, 190), 'status' => 'succeeded', 'fetches' => min(65535, $fetches),
        ])->save();
    }
}
