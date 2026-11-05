<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Workflows;

use SiteXPost\Modules\AiGateway\Gateway\AiGatewayException;
use SiteXPost\Modules\Research\Ai\ResearchAi;
use SiteXPost\Modules\Research\Analysis\CalendarPlanner;
use SiteXPost\Modules\Research\Analysis\IdeaPlanner;
use SiteXPost\Modules\Research\Analysis\InternalLinkPlanner;
use SiteXPost\Modules\Research\Analysis\KeywordExtractor;
use SiteXPost\Modules\Research\Analysis\ResearchAnalyzer;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\Keyword;
use SiteXPost\Modules\Research\Domain\ResearchNotification;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Domain\SearchConsoleConnection;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\WebsiteKnowledge;
use SiteXPost\Modules\Research\Domain\WebsiteProfile;
use SiteXPost\Modules\Research\SearchConsole\GoogleSearchConsole;
use SiteXPost\Modules\Research\SearchConsole\SearchConsoleException;
use SiteXPost\Modules\Research\Sources\CompetitorCollector;
use SiteXPost\Modules\Research\Sources\DataForSeoClient;
use SiteXPost\Modules\Research\Sources\ProviderUnavailable;
use SiteXPost\Modules\Research\Sources\WebsiteCollector;
use SiteXPost\Modules\Research\Support\KeywordStore;
use SiteXPost\Modules\Research\Support\QuotaManager;
use SiteXPost\Modules\Research\Support\ResearchSettings;
use SiteXPost\Modules\Research\Support\SourceRecorder;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Workflows\Domain\WorkflowRun;
use SiteXPost\Modules\Workflows\Engine\StepContext;
use SiteXPost\Modules\Workflows\Engine\StepFailed;
use SiteXPost\Modules\Workflows\Engine\WorkflowDefinition;

/**
 * "SEO research" durable workflow (Phase 3 engine, queue `research`).
 * Each step is idempotent (upserts keyed by fingerprints) so a retry or a
 * duplicate delivery never duplicates keywords, clusters, gaps or ideas.
 * Paid steps (AI) respect the per-run cost cap and degrade gracefully:
 * the run still produces data-driven results without AI.
 */
final class ResearchDiscoveryWorkflow implements WorkflowDefinition
{
    public const TYPE = 'research.discovery';

    private const STEPS = [
        'prepare' => [2, 'Chuẩn bị'],
        'collect_website' => [10, 'Phân tích website'],
        'collect_search_console' => [20, 'Search Console'],
        'collect_competitors' => [28, 'Nghiên cứu đối thủ'],
        'analyze_profile' => [40, 'Nhận diện doanh nghiệp (AI)'],
        'extract_keywords' => [50, 'Khai thác từ khóa'],
        'expand_keywords' => [58, 'Nghiên cứu ngành (AI)'],
        'enrich_metrics' => [66, 'Số liệu từ khóa'],
        'analyze' => [74, 'Chấm điểm, cụm chủ đề, content gap'],
        'generate_ideas' => [86, 'Đề xuất ý tưởng'],
        'plan_calendar' => [94, 'Lập lịch nội dung'],
        'finalize' => [98, 'Hoàn tất'],
    ];

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly WebsiteCollector $website,
        private readonly CompetitorCollector $competitors,
        private readonly KeywordExtractor $extractor,
        private readonly KeywordStore $store,
        private readonly ResearchAnalyzer $analyzer,
        private readonly IdeaPlanner $ideas,
        private readonly InternalLinkPlanner $links,
        private readonly CalendarPlanner $calendar,
        private readonly ResearchAi $ai,
        private readonly GoogleSearchConsole $gsc,
        private readonly DataForSeoClient $dataForSeo,
        private readonly SourceRecorder $recorder,
        private readonly QuotaManager $quota,
        private readonly ResearchSettings $settings,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function queue(array $input): string
    {
        return 'research';
    }

    public function maxAttempts(array $input): int
    {
        return 3;
    }

    public function steps(): array
    {
        return array_keys(self::STEPS);
    }

    public function isPaidStep(string $step): bool
    {
        return in_array($step, ['analyze_profile', 'expand_keywords', 'generate_ideas'], true);
    }

    public function allowsPaidRetry(WorkflowRun $run): bool
    {
        return false;
    }

    public function runStep(string $step, WorkflowRun $run, StepContext $context): array
    {
        $research = ResearchRun::query()->findOrFail((string) ($run->input['research_run_id'] ?? ''));
        $campaign = SeoCampaign::query()->findOrFail($research->campaign_id);
        $research->forceFill([
            'status' => 'running', 'stage' => $step, 'progress' => self::STEPS[$step][0],
            'started_at' => $research->started_at ?? now(), 'workflow_run_id' => $run->id,
        ])->save();

        return match ($step) {
            'prepare' => $this->prepare($campaign),
            'collect_website' => $this->website->collect($campaign, $research),
            'collect_search_console' => $this->searchConsole($campaign, $research),
            'collect_competitors' => $this->competitors->collect($campaign, $research, $this->settings->limitsFor($campaign->workspace_id)['max_competitors_per_campaign']),
            'analyze_profile' => $this->profile($campaign, $research, $run, $context),
            'extract_keywords' => ['added' => $this->extractor->extract($campaign, $research)],
            'expand_keywords' => $this->expand($campaign, $research, $run, $context),
            'enrich_metrics' => $this->metrics($campaign, $research),
            'analyze' => $this->analyzer->analyze($campaign, $research->id),
            'generate_ideas' => $this->generate($campaign, $research, $run, $context),
            'plan_calendar' => $campaign->settingsOrDefault()->auto_calendar
                ? $this->calendar->plan($campaign, true)
                : ['skipped' => 'auto_calendar_off'],
            'finalize' => $this->finalize($campaign, $research, $context),
            default => throw new StepFailed('unknown_step', 'Unknown step '.$step),
        };
    }

    public function output(WorkflowRun $run, array $outputs): array
    {
        return $outputs['finalize'] ?? [];
    }

    public static function stageLabel(?string $step): ?string
    {
        return $step !== null ? (self::STEPS[$step][1] ?? $step) : null;
    }

    // ---------------------------------------------------------------- steps

    /**
     * @return array<string, mixed>
     */
    private function prepare(SeoCampaign $campaign): array
    {
        $site = $this->sites->find($campaign->site_id);
        if ($site === null) {
            throw new StepFailed('site_missing', __('research.errors.site_missing'));
        }
        if ($campaign->status === 'archived') {
            throw new StepFailed('campaign_archived', __('research.errors.campaign_archived'));
        }

        return ['site_paired' => $site['paired'], 'site_status' => $site['status'], 'last_synced_at' => $site['last_synced_at']];
    }

    /**
     * @return array<string, mixed>
     */
    private function searchConsole(SeoCampaign $campaign, ResearchRun $run): array
    {
        $conn = SearchConsoleConnection::query()->where('site_id', $campaign->site_id)->where('status', 'active')->first();
        if (! $this->settings->enabled('search_console') || $conn === null || $conn->property_url === null) {
            $this->recorder->source($run, 'search_console', 'Google Search Console', ['status' => 'skipped', 'error_code' => $conn === null ? 'not_connected' : 'no_property',
                'error_message' => 'Chưa kết nối Search Console — điểm số không dùng dữ liệu đo lường.']);

            return ['skipped' => $conn === null ? 'not_connected' : 'no_property'];
        }

        $end = now()->subDays(3)->toDateString();
        $start = now()->subDays(30)->toDateString();
        try {
            $rows = $this->gsc->queries($conn, $conn->property_url, $start, $end, 1000);
        } catch (SearchConsoleException $e) {
            $conn->forceFill(['last_error_code' => $e->errorCode, 'status' => $e->errorCode === 'reauth_required' ? 'reauth_required' : $conn->status])->save();
            $this->recorder->source($run, 'search_console', 'Google Search Console', ['url' => $conn->property_url, 'status' => 'failed', 'error_code' => $e->errorCode, 'error_message' => mb_substr($e->getMessage(), 0, 500)]);
            $this->settings->recordFailure('search_console', $e->errorCode);

            return ['skipped' => 'error', 'warnings' => ['search_console_failed:'.$e->errorCode]];
        }

        $saved = 0;
        foreach ($rows as $row) {
            if ($row['impressions'] < 5) {
                continue;
            }
            $kw = $this->store->upsert($campaign, ['keyword' => $row['query'], 'source' => 'search_console', 'evidence' => ['type' => 'search_console', 'property' => $conn->property_url, 'period' => "{$start}..{$end}"]], $run->id);
            if ($kw === null) {
                continue;
            }
            $this->store->metric($kw, 'search_console', $start, $end, [
                'impressions' => $row['impressions'], 'clicks' => $row['clicks'], 'ctr' => round($row['ctr'], 4), 'position' => round($row['position'], 2),
            ]);
            $saved++;
        }
        $conn->forceFill(['last_synced_at' => now(), 'last_error_code' => null])->save();
        $this->recorder->source($run, 'search_console', 'Google Search Console', ['url' => $conn->property_url, 'items_count' => $saved]);

        return ['queries' => count($rows), 'keywords' => $saved, 'period' => [$start, $end]];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(SeoCampaign $campaign, ResearchRun $research, WorkflowRun $run, StepContext $context): array
    {
        $profile = WebsiteProfile::query()->where('site_id', $campaign->site_id)->first();
        if ($profile === null) {
            return ['skipped' => 'no_profile'];
        }
        $fresh = $profile->analyzed_at !== null && $profile->analyzed_at->isAfter(now()->subDays((int) $this->settings->global('profile_refresh_days')));
        if ($fresh && ! ($run->input['force_profile'] ?? false)) {
            return ['skipped' => 'recent'];
        }
        if (($skip = $this->aiBlocked($campaign, $research)) !== null) {
            return ['skipped' => $skip, 'warnings' => [$skip]];
        }

        // Evidence: numbered facts so the model can only cite what we sent.
        $evidence = [];
        $ref = 0;
        $evidence[] = ['ref' => ++$ref, 'kind' => 'site', 'text' => trim(($profile->title ?? '').' — '.($profile->description ?? '')), 'url' => null];
        foreach (array_slice($profile->covered_topics ?? [], 0, 25) as $t) {
            $evidence[] = ['ref' => ++$ref, 'kind' => 'category', 'text' => $t['name'].' ('.$t['count'].' bài)', 'url' => null];
        }
        foreach ($this->sites->content($campaign->site_id, ['product', 'post', 'page'], 60) as $c) {
            $evidence[] = ['ref' => ++$ref, 'kind' => $c['post_type'], 'text' => mb_substr($c['title'].' — '.($c['excerpt'] ?? ''), 0, 240), 'url' => $c['url']];
        }

        try {
            $res = $this->ai->profile([
                'industry' => $campaign->industry, 'market' => $campaign->market, 'language' => $campaign->language,
                'products' => $campaign->products ?? [], 'services' => $campaign->services ?? [], 'audiences' => $campaign->audiences ?? [],
            ], $evidence, $context->callKey(), $run->id);
        } catch (AiGatewayException $e) {
            return $this->aiFailure($e, $run, 'profile');
        }
        $this->spend($research, $res['cost_micro_usd']);

        $byRef = [];
        foreach ($evidence as $e) {
            $byRef[$e['ref']] = $e;
        }
        $saved = 0;
        foreach ((array) (($res['json']['statements'] ?? null) ?? []) as $st) {
            if (! is_array($st) || ! is_string($st['value'] ?? null) || ! in_array($st['kind'] ?? null, ['industry', 'brand', 'positioning', 'product_line', 'service', 'audience', 'region', 'usp', 'covered_topic', 'uncertainty'], true)) {
                continue;
            }
            $refs = array_values(array_filter(array_map('intval', (array) ($st['evidence_refs'] ?? [])), fn ($r) => isset($byRef[$r])));
            $ev = array_map(fn ($r) => ['ref' => $r, 'kind' => $byRef[$r]['kind'], 'text' => $byRef[$r]['text'], 'url' => $byRef[$r]['url']], array_slice($refs, 0, 5));
            // Unsupported claims are kept as low-confidence suggestions only.
            $confidence = max(0, min(100, (int) ($st['confidence'] ?? 50)));
            if ($ev === []) {
                $confidence = min($confidence, 40);
            }
            if ($this->website->knowledge($profile, (string) $st['kind'], (string) $st['value'], $confidence, $ev, 'ai') !== null) {
                $saved++;
            }
        }
        $profile->forceFill(['analyzed_at' => now()])->save();

        return ['statements' => $saved, 'cost_micro_usd' => $res['cost_micro_usd'], 'model' => $res['model']];
    }

    /**
     * @return array<string, mixed>
     */
    private function expand(SeoCampaign $campaign, ResearchRun $research, WorkflowRun $run, StepContext $context): array
    {
        $settings = $campaign->settingsOrDefault();
        if (! $settings->use_ai_expansion || ! $this->settings->enabled('ai_expansion')) {
            return ['skipped' => 'disabled'];
        }
        if (($skip = $this->aiBlocked($campaign, $research)) !== null) {
            return ['skipped' => $skip, 'warnings' => [$skip]];
        }
        $profile = WebsiteProfile::query()->where('site_id', $campaign->site_id)->first();
        $knowledge = $profile === null ? [] : WebsiteKnowledge::query()->where('website_profile_id', $profile->id)->where('status', '!=', 'rejected')
            ->whereIn('kind', ['industry', 'product_line', 'service', 'audience', 'usp'])->orderByDesc('confidence')->limit(30)->pluck('value')->all();
        $top = Keyword::query()->where('campaign_id', $campaign->id)->where('status', '!=', 'rejected')->whereIn('primary_source', ['campaign', 'wordpress', 'woocommerce', 'search_console'])
            ->orderByDesc('updated_at')->limit(40)->pluck('keyword')->all();

        try {
            $res = $this->ai->expand([
                'industry' => $campaign->industry, 'market' => $campaign->market, 'language' => $campaign->language,
                'products' => $campaign->products ?? [], 'services' => $campaign->services ?? [], 'audiences' => $campaign->audiences ?? [],
                'scope_keywords' => $settings->scope_keywords ?? [], 'excluded_topics' => $settings->excluded_topics ?? [],
                'business_knowledge' => $knowledge, 'known_keywords' => $top,
            ], $context->callKey(), $run->id);
        } catch (AiGatewayException $e) {
            return $this->aiFailure($e, $run, 'expand');
        }
        $this->spend($research, $res['cost_micro_usd']);

        // Stay inside the industry: an AI keyword must share a topic word
        // with the campaign vocabulary or its own group topic must.
        $vocab = $this->extractor->vocabulary($campaign)['all'];
        $added = 0;
        $dropped = 0;
        foreach (array_slice((array) (($res['json']['groups'] ?? null) ?? []), 0, 12) as $group) {
            if (! is_array($group)) {
                continue;
            }
            $topic = Text::clean($group['topic'] ?? '', 120);
            $topicIn = $this->extractor->touches($topic, $vocab);
            foreach (array_slice((array) ($group['keywords'] ?? []), 0, 8) as $k) {
                $k = Text::clean($k, 190);
                if ($k === '' || (! $topicIn && ! $this->extractor->touches($k, $vocab))) {
                    $dropped++;

                    continue;
                }
                $kw = $this->store->upsert($campaign, ['keyword' => $k, 'source' => 'ai_expansion', 'evidence' => ['type' => 'ai_expansion', 'topic' => $topic, 'model' => $res['model']], 'topic' => $topic], $research->id);
                $kw !== null ? $added++ : $dropped++;
            }
        }
        $this->recorder->source($research, 'ai_expansion', 'AI mở rộng chủ đề ngành', ['items_count' => $added]);

        return ['added' => $added, 'dropped_out_of_scope' => $dropped, 'cost_micro_usd' => $res['cost_micro_usd'], 'model' => $res['model']];
    }

    /**
     * @return array<string, mixed>
     */
    private function metrics(SeoCampaign $campaign, ResearchRun $research): array
    {
        if (! $this->dataForSeo->isAvailable()) {
            $this->recorder->source($research, 'keyword_provider', 'DataForSEO', ['status' => 'skipped', 'error_code' => 'not_configured',
                'error_message' => 'Chưa cấu hình nhà cung cấp số liệu từ khóa — search volume/độ khó hiển thị "Chưa có dữ liệu".']);

            return ['skipped' => 'not_configured'];
        }
        $keywords = Keyword::query()->where('campaign_id', $campaign->id)->where('status', '!=', 'rejected')
            ->whereDoesntHave('metrics', fn ($q) => $q->where('source', 'dataforseo')->where('collected_at', '>', now()->subDays(30)))
            ->orderByDesc('opportunity_score')->orderByDesc('updated_at')->limit(200)->get();
        if ($keywords->isEmpty()) {
            return ['skipped' => 'fresh'];
        }
        $texts = $keywords->pluck('keyword')->all();
        try {
            $volumes = $this->dataForSeo->searchVolume($texts, $campaign->market, $campaign->language);
            $kd = $this->dataForSeo->difficulty($texts, $campaign->market, $campaign->language);
        } catch (ProviderUnavailable $e) {
            $this->settings->recordFailure('dataforseo', $e->errorCode);
            $this->recorder->source($research, 'keyword_provider', 'DataForSEO', ['status' => 'failed', 'error_code' => $e->errorCode, 'error_message' => mb_substr($e->getMessage(), 0, 500)]);

            return ['skipped' => 'error', 'warnings' => ['keyword_provider_failed:'.$e->errorCode]];
        }
        $period = now()->startOfMonth()->toDateString();
        $saved = 0;
        foreach ($keywords as $kw) {
            $key = mb_strtolower($kw->keyword);
            if (! isset($volumes[$key]) && ! isset($kd[$key])) {
                continue;
            }
            $this->store->metric($kw, 'dataforseo', $period, now()->endOfMonth()->toDateString(), [
                'search_volume' => $volumes[$key]['search_volume'] ?? null, 'cpc_micro_usd' => $volumes[$key]['cpc_micro_usd'] ?? null,
                'trend' => $volumes[$key]['trend'] ?? null, 'difficulty' => $kd[$key] ?? null,
            ]);
            if (! in_array('dataforseo', $kw->sources ?? [], true)) {
                $kw->forceFill(['sources' => array_values(array_merge($kw->sources ?? [], ['dataforseo']))])->save();
            }
            $saved++;
        }
        $this->recorder->source($research, 'keyword_provider', 'DataForSEO', ['items_count' => $saved, 'url' => 'https://api.dataforseo.com']);

        return ['keywords' => $saved];
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(SeoCampaign $campaign, ResearchRun $research, WorkflowRun $run, StepContext $context): array
    {
        $limits = $this->settings->limitsFor($campaign->workspace_id);
        $limit = max(1, min($limits['max_ideas_per_run'], $campaign->settingsOrDefault()->ideas_per_run));
        $groups = $this->ideas->candidates($campaign, $limit);
        if ($groups === []) {
            return ['created' => 0, 'reason' => 'no_new_opportunities', 'links' => $this->links->plan($campaign)];
        }

        $phrased = [];
        $warnings = [];
        $cost = 0;
        if (($skip = $this->aiBlocked($campaign, $research)) !== null) {
            $warnings[] = $skip;
        } else {
            try {
                $res = $this->ai->ideas($this->ideas->aiContext($campaign), IdeaPlanner::forAi($groups), $context->callKey(), $run->id);
                $cost = $res['cost_micro_usd'];
                $this->spend($research, $cost);
                foreach ((array) (($res['json']['ideas'] ?? null) ?? []) as $item) {
                    $g = is_array($item) ? (int) ($item['group'] ?? 0) : 0;
                    if ($g >= 1 && $g <= count($groups) && ! isset($phrased[$g])) {
                        $phrased[$g] = $item;
                    }
                }
            } catch (AiGatewayException $e) {
                $failure = $this->aiFailure($e, $run, 'ideas'); // rethrows while retries remain
                $warnings = array_merge($warnings, $failure['warnings'] ?? []);
            }
        }

        $stats = $this->ideas->save($campaign, $research->id, $groups, $phrased);
        $this->quota->addUsage('ideas', $stats['created']);

        return $stats + ['candidates' => count($groups), 'cost_micro_usd' => $cost, 'links' => $this->links->plan($campaign), 'warnings' => $warnings];
    }

    /**
     * @return array<string, mixed>
     */
    private function finalize(SeoCampaign $campaign, ResearchRun $research, StepContext $context): array
    {
        $warnings = [];
        foreach ($context->outputs as $out) {
            foreach ((array) ($out['warnings'] ?? []) as $w) {
                $warnings[] = (string) $w;
            }
        }
        $warnings = array_values(array_unique($warnings));
        $ideas = ContentIdea::query()->where('research_run_id', $research->id)->get(['id', 'opportunity_score', 'title']);
        $stats = [
            'keywords_total' => Keyword::query()->where('campaign_id', $campaign->id)->count(),
            'keywords_added' => array_sum((array) ($context->outputs['extract_keywords']['added'] ?? [])) + (int) ($context->outputs['expand_keywords']['added'] ?? 0) + (int) ($context->outputs['collect_search_console']['keywords'] ?? 0),
            'clusters' => (int) ($context->outputs['analyze']['clusters'] ?? 0),
            'gaps_new' => (int) ($context->outputs['analyze']['gaps'] ?? 0),
            'ideas_created' => $ideas->count(),
            'ideas_ai' => (int) ($context->outputs['generate_ideas']['ai'] ?? 0),
            'ideas_rule' => (int) ($context->outputs['generate_ideas']['rule'] ?? 0),
            'calendar_scheduled' => (int) ($context->outputs['plan_calendar']['scheduled'] ?? 0),
            'pages_crawled' => (int) ($context->outputs['collect_website']['pages'] ?? 0),
            'competitor_findings' => (int) ($context->outputs['collect_competitors']['findings'] ?? 0),
        ];

        $settings = $campaign->settingsOrDefault();
        $strong = $ideas->filter(fn ($i) => ($i->opportunity_score ?? 0) >= $settings->notify_min_score);
        if ($settings->notify_opportunities && $strong->isNotEmpty() && ! ResearchNotification::query()->where('dedupe_key', sha1('run:'.$research->id))->exists()) {
            $n = new ResearchNotification;
            $n->forceFill([
                'campaign_id' => $campaign->id, 'type' => 'opportunities_found',
                'title' => __('research.notifications.opportunities_title', ['count' => $strong->count(), 'campaign' => $campaign->name]),
                'body' => mb_substr($strong->sortByDesc('opportunity_score')->take(3)->pluck('title')->implode(' • '), 0, 1000),
                'data' => ['research_run_id' => $research->id, 'idea_ids' => $strong->pluck('id')->take(20)->values()->all()],
                'dedupe_key' => sha1('run:'.$research->id),
            ])->save();
        }

        $partial = $warnings !== [];
        $research->forceFill([
            'status' => $partial ? 'partial' : 'succeeded', 'stage' => null, 'progress' => 100,
            'stats' => $stats, 'warnings' => $warnings, 'finished_at' => now(),
        ])->save();
        $campaign->forceFill(['last_run_at' => now()])->save();
        $this->quota->settle($research, true);

        return $stats + ['warnings' => $warnings, 'cost_micro_usd' => $research->cost_micro_usd];
    }

    // -------------------------------------------------------------- helpers

    /**
     * Per-run AI cost cap (campaign setting, bounded by the workspace limit).
     */
    private function aiBlocked(SeoCampaign $campaign, ResearchRun $research): ?string
    {
        $cap = min($campaign->settingsOrDefault()->max_ai_cost_per_run_micro_usd, $this->settings->limitsFor($campaign->workspace_id)['max_ai_cost_per_run_micro_usd']);
        $spent = (int) ResearchRun::query()->whereKey($research->id)->value('cost_micro_usd');

        return $spent >= $cap ? 'ai_cost_cap_reached' : null;
    }

    private function spend(ResearchRun $research, int $cost): void
    {
        if ($cost <= 0) {
            return;
        }
        ResearchRun::query()->whereKey($research->id)->increment('cost_micro_usd', $cost);
        $this->quota->addUsage('ai_cost_micro_usd', $cost);
    }

    /**
     * AI is optional for every step: transient errors are retried by the
     * engine (backoff) while attempts remain; after that, or for permanent
     * errors (budget, no model, invalid output), the step continues without
     * AI and records a warning.
     *
     * @return array{skipped: string, warnings: list<string>}
     *
     * @throws StepFailed
     */
    private function aiFailure(AiGatewayException $e, WorkflowRun $run, string $what): array
    {
        $attempts = (int) (WorkflowRun::query()->whereKey($run->id)->value('attempts') ?? 1);
        if ($e->retryable && ! $e->requiresPaidRetry && $attempts < $run->max_attempts) {
            throw new StepFailed($e->errorCode, $e->getMessage(), retryable: true);
        }

        return ['skipped' => 'ai_unavailable', 'warnings' => ["ai_{$what}_failed:".$e->errorCode]];
    }
}
