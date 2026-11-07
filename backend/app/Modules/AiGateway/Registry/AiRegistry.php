<?php

declare(strict_types=1);

namespace SiteXPost\Modules\AiGateway\Registry;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiModelPrice;
use SiteXPost\Modules\AiGateway\Domain\AiProvider;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;

/**
 * Default provider/model/price/route registry. `sync()` only INSERTS what is
 * missing — it never overwrites values a Super Admin changed — so it is safe
 * to run on every deploy (`php artisan sitex:ai:sync-registry`).
 *
 * Prices verified on the providers' official pricing pages on 05/11/2026
 * (standard tier, USD per 1M tokens). Scheduled price changes announced by
 * Google are stored as a second version with its future effective date.
 */
final class AiRegistry
{
    private const VERIFIED_FROM = '2026-11-01 00:00:00';

    private const M = 1_000_000; // micro-USD per USD

    /**
     * @return array<string, array{name: string, priority: int, timeout: int}>
     */
    public static function providers(): array
    {
        return [
            'openai' => ['name' => 'OpenAI', 'priority' => 10, 'timeout' => 90],
            'anthropic' => ['name' => 'Anthropic (Claude)', 'priority' => 20, 'timeout' => 120],
            'gemini' => ['name' => 'Google Gemini', 'priority' => 30, 'timeout' => 90],
            'deepseek' => ['name' => 'DeepSeek', 'priority' => 40, 'timeout' => 120],
            'fake' => ['name' => 'Fake (dev/test only)', 'priority' => 900, 'timeout' => 35],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function models(): array
    {
        $openai = 'https://developers.openai.com/api/docs/pricing';
        $gemini = 'https://aistudio.google.com/docs/pricing';
        $claude = 'https://platform.claude.com/docs/en/about-claude/pricing';
        $deepseek = 'https://api-docs.deepseek.com/quick_start/pricing/';
        // DeepSeek peak hours (UTC, Mon–Fri): off-peak = half price. Chinese public holidays are not modelled (billed as peak).
        $deepseekPeak = [['days' => [1, 2, 3, 4, 5], 'from' => '01:00', 'to' => '04:00'], ['days' => [1, 2, 3, 4, 5], 'from' => '06:00', 'to' => '10:00']];
        $deepseekCaps = static fn (bool $vision): array => [
            'structured_output' => true, 'web_search' => false, 'image_generation' => false, 'temperature' => true,
            'vision' => $vision, 'context_window' => 1000000, 'max_output_tokens' => 384000, 'reasoning_effort' => 'none',
        ];
        $text = static fn (bool $search, bool $temperature, int $context, int $maxOut): array => [
            'structured_output' => true, 'web_search' => $search, 'image_generation' => false, 'temperature' => $temperature,
            'vision' => true, 'context_window' => $context, 'max_output_tokens' => $maxOut,
        ];

        return [
            // OpenAI — Responses API. Reasoning models: temperature not sent.
            ['provider' => 'openai', 'name' => 'gpt-6.1-sol', 'display' => 'GPT-6.1 Sol', 'tier' => 'standard', 'caps' => $text(true, false, 400000, 128000),
                'prices' => [['in' => 2.00, 'cached' => 0.10, 'out' => 10.00, 'search_1k' => 10.00, 'source' => $openai]]],
            ['provider' => 'openai', 'name' => 'gpt-6.1-terra', 'display' => 'GPT-6.1 Terra', 'tier' => 'standard', 'caps' => $text(true, false, 400000, 128000),
                'prices' => [['in' => 1.00, 'cached' => 0.05, 'out' => 5.00, 'search_1k' => 10.00, 'source' => $openai]]],
            ['provider' => 'openai', 'name' => 'gpt-6-luna', 'display' => 'GPT-6 Luna', 'tier' => 'economy', 'caps' => $text(true, false, 400000, 128000),
                'prices' => [['in' => 0.10, 'cached' => 0.01, 'out' => 0.50, 'search_1k' => 10.00, 'source' => $openai]]],
            ['provider' => 'openai', 'name' => 'gpt-6-astra', 'display' => 'GPT-6 Astra', 'tier' => 'premium', 'caps' => $text(true, false, 400000, 128000),
                'prices' => [['in' => 10.00, 'cached' => 1.00, 'out' => 50.00, 'search_1k' => 10.00, 'source' => $openai]]],
            ['provider' => 'openai', 'name' => 'gpt-image-2.5-sunburst', 'display' => 'GPT Image 2.5 Sunburst', 'tier' => 'standard', 'kind' => 'image', 'enabled' => false,
                'caps' => ['structured_output' => false, 'web_search' => false, 'image_generation' => true, 'temperature' => false, 'vision' => true],
                'notes' => 'Image tokens: $8 in / $30 out per 1M; text in $5. Per-image cost depends on size/quality — not verified with a live key; disabled by default.',
                'prices' => [['in' => 5.00, 'cached' => 1.25, 'out' => 30.00, 'source' => $openai]]],

            // Google Gemini — generateContent. Temperature silently ignored on 3.6 Flash+.
            ['provider' => 'gemini', 'name' => 'gemini-3.8-flash', 'display' => 'Gemini 3.8 Flash', 'tier' => 'standard', 'caps' => $text(false, false, 1000000, 65536),
                'notes' => 'Google Search grounding: $14 / 1K requests (5K free/month not deducted). Search capability left off until verified with a live key.',
                'prices' => [
                    ['in' => 0.75, 'cached' => 0.075, 'out' => 3.75, 'search_1k' => 14.00, 'source' => $gemini],
                    ['in' => 1.50, 'cached' => 0.15, 'out' => 7.50, 'search_1k' => 14.00, 'source' => $gemini, 'from' => '2027-01-01 00:00:00', 'notes' => 'Price change announced by Google'],
                ]],
            ['provider' => 'gemini', 'name' => 'gemini-3.5-flash-lite', 'display' => 'Gemini 3.5 Flash-Lite', 'tier' => 'economy', 'caps' => $text(false, false, 1000000, 65536),
                'prices' => [['in' => 0.30, 'cached' => 0.03, 'out' => 2.50, 'search_1k' => 14.00, 'source' => $gemini]]],
            ['provider' => 'gemini', 'name' => 'gemini-4-argon', 'display' => 'Gemini 4 Argon', 'tier' => 'premium', 'caps' => $text(false, false, 1000000, 65536),
                'prices' => [
                    ['in' => 2.00, 'cached' => 0.10, 'out' => 10.00, 'search_1k' => 14.00, 'source' => $gemini],
                    ['in' => 4.00, 'cached' => 0.20, 'out' => 20.00, 'search_1k' => 14.00, 'source' => $gemini, 'from' => '2026-12-01 00:00:00', 'notes' => 'Price change announced by Google'],
                ]],

            // Anthropic — Messages API (thinking billed as output tokens).
            ['provider' => 'anthropic', 'name' => 'claude-opus-5-5', 'display' => 'Claude Opus 5.5', 'tier' => 'premium', 'caps' => $text(true, false, 1000000, 128000),
                'prices' => [['in' => 4.00, 'cached' => 0.20, 'write' => 5.00, 'out' => 20.00, 'search_1k' => 10.00, 'source' => $claude]]],
            ['provider' => 'anthropic', 'name' => 'claude-sonnet-5-5', 'display' => 'Claude Sonnet 5.5', 'tier' => 'standard', 'caps' => $text(true, false, 1000000, 128000),
                'prices' => [['in' => 2.00, 'cached' => 0.10, 'write' => 2.50, 'out' => 10.00, 'search_1k' => 10.00, 'source' => $claude]]],
            ['provider' => 'anthropic', 'name' => 'claude-haiku-5-5', 'display' => 'Claude Haiku 5.5', 'tier' => 'economy', 'caps' => $text(false, false, 1000000, 128000),
                'prices' => [['in' => 0.10, 'cached' => 0.01, 'write' => 0.125, 'out' => 0.50, 'source' => $claude,
                    'long' => ['threshold' => 100000, 'in' => 0.50, 'cached' => 0.05, 'write' => 0.625, 'out' => 2.50]]]],

            // DeepSeek — Chat Completions; json_object only (schema validated by the gateway); thinking off unless reasoning_effort is set.
            // Disabled until a Super Admin adds a key and runs "Test model".
            ['provider' => 'deepseek', 'name' => 'deepseek-flash', 'display' => 'DeepSeek Flash (V4.1)', 'tier' => 'economy', 'enabled' => false, 'caps' => $deepseekCaps(true),
                'notes' => 'Points to DeepSeek-V4.1-Flash-1017 (05/11/2026). Concurrency limit 2500.',
                'prices' => [['in' => 0.30, 'cached' => 0.006, 'out' => 1.20, 'source' => $deepseek, 'peak' => $deepseekPeak,
                    'offpeak' => ['in' => 0.15, 'cached' => 0.003, 'out' => 0.60], 'notes' => 'Verified 2026-11-05; peak rates, off-peak = half']]],
            ['provider' => 'deepseek', 'name' => 'deepseek-v4-pro', 'display' => 'DeepSeek V4 Pro', 'tier' => 'standard', 'enabled' => false, 'caps' => $deepseekCaps(false),
                'notes' => 'DeepSeek-V4-Pro-0813 (05/11/2026). Concurrency limit 500. Max output for Pro not stated separately in the docs.',
                'prices' => [['in' => 1.32, 'cached' => 0.044, 'out' => 3.96, 'source' => $deepseek, 'peak' => $deepseekPeak,
                    'offpeak' => ['in' => 0.66, 'cached' => 0.022, 'out' => 1.98], 'notes' => 'Verified 2026-11-05; peak rates, off-peak = half']]],

            // Offline provider (never in production) — nominal price so cost tracking is visible in dev.
            ['provider' => 'fake', 'name' => 'fake-echo', 'display' => 'Fake Echo (dev)', 'tier' => 'economy', 'caps' => $text(true, false, 100000, 8000),
                'prices' => [['in' => 1.00, 'out' => 2.00, 'search_1k' => 10.00, 'source' => null, 'notes' => 'Nominal test price']]],
            ['provider' => 'fake', 'name' => 'fake-image', 'display' => 'Fake Image (dev)', 'tier' => 'economy', 'kind' => 'image',
                'caps' => ['structured_output' => false, 'web_search' => false, 'image_generation' => true, 'temperature' => false, 'vision' => false],
                'prices' => [['in' => 1.00, 'out' => 0, 'image' => 0.02, 'source' => null, 'notes' => 'Nominal test price']]],
        ];
    }

    /**
     * Task routes. Only `system.test` is used in Phase 3; the others are the
     * routes the content pipeline (later phases) will call.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function routes(): array
    {
        return [
            'system.test' => ['desc' => 'AI task test (Phase 3 acceptance)', 'queue' => 'writing', 'priority' => 'interactive',
                'primary' => 'openai/gpt-6-luna', 'economy' => null, 'fallbacks' => ['anthropic/claude-haiku-5-5', 'gemini/gemini-3.5-flash-lite', 'fake/fake-echo'],
                'max_out' => 800, 'max_attempts' => 3],
            'research.summarize' => ['desc' => 'Summarise downloaded research sources', 'queue' => 'research', 'priority' => 'batch',
                'primary' => 'anthropic/claude-sonnet-5-5', 'economy' => 'anthropic/claude-haiku-5-5', 'fallbacks' => ['openai/gpt-6.1-terra', 'gemini/gemini-3.8-flash'], 'max_out' => 4000],
            'keywords.expand' => ['desc' => 'Keyword ideas (no fabricated metrics)', 'queue' => 'research', 'priority' => 'batch',
                'primary' => 'gemini/gemini-3.8-flash', 'economy' => 'gemini/gemini-3.5-flash-lite', 'fallbacks' => ['openai/gpt-6.1-terra', 'deepseek/deepseek-flash', 'fake/fake-echo'], 'max_out' => 3000],
            // Phase 4 — topic discovery (models are defaults; Super Admin re-routes them in AI Settings).
            'research.profile' => ['desc' => 'Website knowledge profile (industry, brand, audience)', 'queue' => 'research', 'priority' => 'batch',
                'primary' => 'gemini/gemini-3.8-flash', 'economy' => 'gemini/gemini-3.5-flash-lite', 'fallbacks' => ['openai/gpt-6.1-terra', 'deepseek/deepseek-flash', 'fake/fake-echo'], 'max_out' => 2500],
            'topics.ideas' => ['desc' => 'SEO content ideas from collected evidence', 'queue' => 'research', 'priority' => 'batch',
                'primary' => 'anthropic/claude-sonnet-5-5', 'economy' => 'gemini/gemini-3.8-flash', 'fallbacks' => ['openai/gpt-6.1-terra', 'deepseek/deepseek-flash', 'fake/fake-echo'], 'max_out' => 6000],
            'content.draft' => ['desc' => 'Article drafting', 'queue' => 'writing', 'priority' => 'batch',
                'primary' => 'anthropic/claude-sonnet-5-5', 'economy' => 'openai/gpt-6.1-terra', 'fallbacks' => ['openai/gpt-6.1-sol', 'gemini/gemini-3.8-flash', 'fake/fake-echo'], 'max_out' => 12000, 'max_in' => 120000],
            'verification.claims' => ['desc' => 'Claim verification against sources', 'queue' => 'factcheck', 'priority' => 'batch',
                'primary' => 'openai/gpt-6.1-sol', 'economy' => 'openai/gpt-6.1-terra', 'fallbacks' => ['anthropic/claude-sonnet-5-5', 'fake/fake-echo'], 'max_out' => 4000, 'max_in' => 120000],
            // Phase 5 — AI Writer & Fact-Check (defaults only; Super Admin re-routes each task in AI Settings).
            // `content.draft` (writing) and `verification.claims` (fact-check reasoning) above are reused.
            'writer.research_questions' => ['desc' => 'Writer: research questions for an article', 'queue' => 'research', 'priority' => 'batch',
                'primary' => 'gemini/gemini-3.8-flash', 'economy' => 'gemini/gemini-3.5-flash-lite', 'fallbacks' => ['openai/gpt-6.1-terra', 'deepseek/deepseek-flash', 'anthropic/claude-sonnet-5-5', 'fake/fake-echo'], 'max_out' => 1500],
            'writer.source_discovery' => ['desc' => 'Writer: candidate source URLs (always fetched and verified by the system)', 'queue' => 'research', 'priority' => 'batch',
                'primary' => 'gemini/gemini-3.8-flash', 'economy' => 'gemini/gemini-3.5-flash-lite', 'fallbacks' => ['openai/gpt-6.1-terra', 'anthropic/claude-sonnet-5-5', 'fake/fake-echo'], 'max_out' => 1500],
            'writer.source_analysis' => ['desc' => 'Writer: extract verbatim evidence from retrieved sources', 'queue' => 'research', 'priority' => 'batch',
                'primary' => 'gemini/gemini-3.8-flash', 'economy' => 'gemini/gemini-3.5-flash-lite', 'fallbacks' => ['openai/gpt-6.1-terra', 'deepseek/deepseek-flash', 'anthropic/claude-sonnet-5-5', 'fake/fake-echo'], 'max_out' => 3000],
            'writer.brief' => ['desc' => 'Writer: content brief from idea + research dossier', 'queue' => 'writing', 'priority' => 'batch',
                'primary' => 'anthropic/claude-sonnet-5-5', 'economy' => 'gemini/gemini-3.8-flash', 'fallbacks' => ['openai/gpt-6.1-terra', 'deepseek/deepseek-flash', 'fake/fake-echo'], 'max_out' => 4000],
            'writer.outline' => ['desc' => 'Writer: structured outline with evidence references', 'queue' => 'writing', 'priority' => 'batch',
                'primary' => 'anthropic/claude-sonnet-5-5', 'economy' => 'gemini/gemini-3.8-flash', 'fallbacks' => ['openai/gpt-6.1-terra', 'deepseek/deepseek-flash', 'fake/fake-echo'], 'max_out' => 4000],
            'writer.claims' => ['desc' => 'Writer: factual claim extraction', 'queue' => 'factcheck', 'priority' => 'batch',
                'primary' => 'openai/gpt-6.1-terra', 'economy' => 'gemini/gemini-3.5-flash-lite', 'fallbacks' => ['gemini/gemini-3.8-flash', 'deepseek/deepseek-flash', 'fake/fake-echo'], 'max_out' => 3000],
            'writer.rewrite' => ['desc' => 'Writer: section rewrite / fix (only the affected sentences)', 'queue' => 'writing', 'priority' => 'interactive',
                'primary' => 'anthropic/claude-sonnet-5-5', 'economy' => 'openai/gpt-6.1-terra', 'fallbacks' => ['gemini/gemini-3.8-flash', 'deepseek/deepseek-flash', 'fake/fake-echo'], 'max_out' => 4000],
            'writer.seo_review' => ['desc' => 'Writer: SEO review notes (advisory, never a publish condition)', 'queue' => 'writing', 'priority' => 'batch',
                'primary' => 'gemini/gemini-3.8-flash', 'economy' => 'gemini/gemini-3.5-flash-lite', 'fallbacks' => ['openai/gpt-6.1-terra', 'fake/fake-echo'], 'max_out' => 1500],
            'writer.final_review' => ['desc' => 'Writer: final editorial review notes (advisory)', 'queue' => 'writing', 'priority' => 'batch',
                'primary' => 'openai/gpt-6.1-terra', 'economy' => 'gemini/gemini-3.5-flash-lite', 'fallbacks' => ['gemini/gemini-3.8-flash', 'fake/fake-echo'], 'max_out' => 1500],
            'writer.style_profile' => ['desc' => 'Writer: learn the writing style of sample articles (style guide only, never copied text)', 'queue' => 'writing', 'priority' => 'interactive',
                'primary' => 'anthropic/claude-sonnet-5-5', 'economy' => 'gemini/gemini-3.8-flash', 'fallbacks' => ['openai/gpt-6.1-terra', 'deepseek/deepseek-flash', 'fake/fake-echo'], 'max_out' => 3000, 'max_in' => 60000],
            // Phase 8 — SEO analytics & agent (defaults only; AI never produces metrics, it phrases and suggests).
            'analytics.recommend' => ['desc' => 'SEO recommendation wording: title/meta suggestions from GSC evidence (never metrics)', 'queue' => 'analytics', 'priority' => 'batch',
                'primary' => 'gemini/gemini-3.8-flash', 'economy' => 'gemini/gemini-3.5-flash-lite', 'fallbacks' => ['openai/gpt-6.1-terra', 'deepseek/deepseek-flash', 'fake/fake-echo'], 'max_out' => 1500],
            'agent.plan' => ['desc' => 'SEO agent: rank candidate tasks with rationale (tools are fixed; policy decides)', 'queue' => 'analytics', 'priority' => 'batch',
                'primary' => 'openai/gpt-6.1-terra', 'economy' => 'gemini/gemini-3.5-flash-lite', 'fallbacks' => ['gemini/gemini-3.8-flash', 'deepseek/deepseek-flash', 'fake/fake-echo'], 'max_out' => 2000],
            'copilot.answer' => ['desc' => 'AI SEO Copilot: answer from tenant data (facts / inferences / recommendations)', 'queue' => 'analytics', 'priority' => 'interactive',
                'primary' => 'gemini/gemini-3.8-flash', 'economy' => 'gemini/gemini-3.5-flash-lite', 'fallbacks' => ['openai/gpt-6.1-terra', 'deepseek/deepseek-flash', 'fake/fake-echo'], 'max_out' => 2000],
            'media.image' => ['desc' => 'AI image generation (always labelled as AI)', 'queue' => 'media', 'priority' => 'batch',
                'primary' => 'openai/gpt-image-2.5-sunburst', 'economy' => null, 'fallbacks' => ['fake/fake-image'], 'max_out' => 1],
        ];
    }

    /**
     * @return array{providers: int, models: int, prices: int, routes: int}
     */
    public function sync(): array
    {
        $created = ['providers' => 0, 'models' => 0, 'prices' => 0, 'routes' => 0];

        DB::transaction(function () use (&$created): void {
            foreach (self::providers() as $code => $p) {
                $provider = AiProvider::query()->where('code', $code)->first();
                if ($provider === null) {
                    $provider = new AiProvider;
                    $provider->forceFill([
                        'code' => $code, 'driver' => $code, 'name' => $p['name'], 'priority' => $p['priority'], 'timeout_seconds' => $p['timeout'],
                        'is_enabled' => true, 'max_retries' => 1, 'circuit_failure_threshold' => 5, 'circuit_cooldown_seconds' => 60,
                    ])->save();
                    $created['providers']++;
                }
            }

            $providers = AiProvider::query()->get()->keyBy('code');
            foreach (self::models() as $m) {
                $provider = $providers->get($m['provider']);
                if ($provider === null) {
                    continue;
                }
                $key = $m['provider'].'/'.$m['name'];
                $model = AiModel::query()->where('model_key', $key)->first();
                if ($model === null) {
                    $model = new AiModel;
                    $model->forceFill([
                        'provider_id' => $provider->id, 'model_key' => $key, 'model_name' => $m['name'], 'display_name' => $m['display'],
                        'kind' => $m['kind'] ?? 'text', 'tier' => $m['tier'], 'capabilities' => $m['caps'],
                        'is_enabled' => $m['enabled'] ?? true, 'notes' => $m['notes'] ?? null,
                    ])->save();
                    $created['models']++;
                }
                if (AiModelPrice::query()->where('model_id', $model->id)->exists()) {
                    continue;
                }
                foreach ($m['prices'] as $i => $p) {
                    $price = new AiModelPrice;
                    $price->forceFill([
                        'model_id' => $model->id,
                        'version' => $i + 1,
                        'effective_from' => Carbon::parse($p['from'] ?? self::VERIFIED_FROM, 'UTC'),
                        'currency' => 'USD',
                        'input_per_mtok' => self::micro($p['in'] ?? 0),
                        'cached_input_per_mtok' => self::micro($p['cached'] ?? 0),
                        'cache_write_per_mtok' => self::micro($p['write'] ?? 0),
                        'output_per_mtok' => self::micro($p['out'] ?? 0),
                        'long_context_threshold' => $p['long']['threshold'] ?? null,
                        'long_input_per_mtok' => isset($p['long']) ? self::micro($p['long']['in']) : null,
                        'long_cached_input_per_mtok' => isset($p['long']) ? self::micro($p['long']['cached']) : null,
                        'long_cache_write_per_mtok' => isset($p['long']) ? self::micro($p['long']['write']) : null,
                        'long_output_per_mtok' => isset($p['long']) ? self::micro($p['long']['out']) : null,
                        'per_image' => self::micro($p['image'] ?? 0),
                        'per_1k_searches' => self::micro($p['search_1k'] ?? 0),
                        'offpeak_input_per_mtok' => isset($p['offpeak']) ? self::micro($p['offpeak']['in']) : null,
                        'offpeak_cached_input_per_mtok' => isset($p['offpeak']) ? self::micro($p['offpeak']['cached']) : null,
                        'offpeak_output_per_mtok' => isset($p['offpeak']) ? self::micro($p['offpeak']['out']) : null,
                        'peak_windows' => $p['peak'] ?? null,
                        'source_url' => $p['source'] ?? null,
                        'notes' => $p['notes'] ?? 'Verified 2026-11-05 (standard tier)',
                    ])->save();
                    $created['prices']++;
                }
            }

            $models = AiModel::query()->get()->keyBy('model_key');
            foreach (self::routes() as $taskKey => $r) {
                if (AiTaskRoute::query()->where('task_key', $taskKey)->exists() || ! $models->has($r['primary'])) {
                    continue;
                }
                $route = new AiTaskRoute;
                $route->forceFill([
                    'task_key' => $taskKey,
                    'description' => $r['desc'],
                    'queue' => $r['queue'],
                    'priority' => $r['priority'],
                    'primary_model_id' => $models->get($r['primary'])?->id,
                    'economy_model_id' => $r['economy'] !== null ? $models->get($r['economy'])?->id : null,
                    'fallback_model_ids' => array_values(array_filter(array_map(fn (string $k): ?string => $models->get($k)?->id, $r['fallbacks']))),
                    'max_input_tokens' => $r['max_in'] ?? 30000,
                    'max_output_tokens' => $r['max_out'],
                    'max_attempts' => $r['max_attempts'] ?? 3,
                    'allow_paid_retry' => false,
                    'is_enabled' => true,
                ])->save();
                $created['routes']++;
            }
        });

        return $created;
    }

    private static function micro(float|int $usd): int
    {
        return (int) round($usd * self::M);
    }
}
