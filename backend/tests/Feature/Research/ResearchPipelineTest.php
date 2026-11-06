<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use SiteXPost\Modules\AiGateway\Gateway\AiGateway;
use SiteXPost\Modules\Research\Ai\ResearchAi;
use SiteXPost\Modules\Research\Analysis\IdeaPlanner;
use SiteXPost\Modules\Research\Domain\ContentCalendarItem;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\Keyword;
use SiteXPost\Modules\Research\Domain\KeywordMetric;
use SiteXPost\Modules\Research\Domain\ResearchLimit;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Domain\ResearchSource;
use SiteXPost\Modules\Research\Domain\ResearchSourceSetting;

/**
 * Phase 4 acceptance: the planner finds useful topics from the site's own
 * data, never fabricates metrics, never duplicates topics, and degrades
 * gracefully (no Search Console, AI down, WordPress offline, quotas).
 */
function startRun(array $t, ?string $key = null): TestResponse
{
    return test()->actingAs($t['owner'])->postJson(researchUrl($t, 'runs'), [], $key !== null ? ['Idempotency-Key' => $key] : []);
}

function runDetail(array $t, string $id): array
{
    return test()->actingAs($t['owner'])->getJson(researchUrl($t, "runs/{$id}"))->assertOk()->json('data');
}

it('discovers keywords and topic ideas from the website data', function () {
    $t = researchTenant();

    $res = startRun($t, 'run-key-0001')->assertStatus(202);
    $run = runDetail($t, $res->json('data.research_run.id'));

    expect($run['status'])->toBe('succeeded')
        ->and(collect($run['steps'])->pluck('status')->unique()->all())->toBe(['succeeded'])
        ->and($run['stats']['pages_crawled'])->toBeGreaterThan(1)
        ->and($run['stats']['ideas_created'])->toBeGreaterThan(3);

    // Every source consulted is recorded with its URL and time.
    $types = collect($run['sources'])->pluck('type')->unique()->all();
    expect($types)->toContain('wordpress', 'website_crawl', 'search_console', 'keyword_provider');

    // Keywords come from real site vocabulary and never cut Vietnamese words.
    $keywords = collect($this->actingAs($t['owner'])->getJson(researchUrl($t, 'keywords').'?per_page=100')->assertOk()->json('data'))->pluck('keyword');
    expect($keywords)->toContain('tai nghe', 'laptop', 'bàn phím')
        ->and($keywords->filter(fn ($k) => preg_match('/^(5 tai|nghe chống|sinh bàn|hại tai)/u', $k)))->toBeEmpty();

    // Ideas carry evidence, a transparent score and a dedupe fingerprint.
    $ideas = $this->actingAs($t['owner'])->getJson(researchUrl($t, 'ideas'))->assertOk()->json('data');
    $first = $ideas[0];
    expect($first['opportunity']['kind'])->toBe('internal_estimate')
        ->and($first['opportunity']['components'])->not->toBeEmpty()
        ->and($first['primary_keyword']['keyword'])->not->toBeEmpty()
        ->and(collect($ideas)->pluck('title'))->toContain('Laptop nào tốt cho lập trình?');

    // Content calendar is never filled and nothing is written to WordPress.
    expect(inResearchTenant($t, fn () => ContentCalendarItem::query()->count()))->toBe(0);
});

it('never fabricates search volume or difficulty without a verified provider', function () {
    $t = researchTenant();
    $id = startRun($t)->json('data.research_run.id');

    expect(inResearchTenant($t, fn () => KeywordMetric::query()->count()))->toBe(0);
    $kw = $this->actingAs($t['owner'])->getJson(researchUrl($t, 'keywords'))->json('data.0');
    expect($kw['metrics']['search_volume'])->toBeNull()
        ->and($kw['metrics']['difficulty'])->toBeNull()
        ->and($kw['metrics']['provider'])->toBeNull()
        ->and($kw['opportunity']['kind'])->toBe('internal_estimate')
        ->and(collect($kw['opportunity']['components'])->whereIn('key', ['market_demand', 'difficulty'])->pluck('available')->unique()->all())->toBe([false])
        ->and(collect($kw['opportunity']['components'])->whereIn('key', ['market_demand', 'difficulty'])->pluck('points')->filter()->all())->toBe([]);

    $provider = collect(runDetail($t, $id)['sources'])->firstWhere('type', 'keyword_provider');
    expect($provider['status'])->toBe('skipped')->and($provider['error']['code'])->toBe('not_configured');

    // Clients cannot submit metrics either.
    $this->actingAs($t['owner'])->postJson(researchUrl($t, 'keywords'), ['keyword' => 'tai nghe bluetooth', 'search_volume' => 9000])
        ->assertStatus(422)->assertJsonValidationErrors(['search_volume'], 'error.details');
});

it('stores verified DataForSEO metrics with source and collection time', function () {
    $calls = [];
    $t = researchTenant('Tech Store', ['http' => function (HttpRequest $r) use (&$calls) {
        if (! str_starts_with($r->url(), 'https://api.dataforseo.com/')) {
            return null;
        }
        $calls[] = $r->url();
        $kws = $r->data()[0]['keywords'] ?? [];
        if (str_contains($r->url(), 'search_volume')) {
            return Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => array_map(fn ($k) => [
                'keyword' => $k, 'search_volume' => $k === 'tai nghe' ? 49500 : null, 'cpc' => 0.12, 'monthly_searches' => [['year' => 2026, 'month' => 10, 'search_volume' => 40500]],
            ], $kws)]]]);
        }

        return Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [['items' => [['keyword' => 'tai nghe', 'keyword_difficulty' => 38]]]]]]]);
    }]);
    $setting = new ResearchSourceSetting;
    $setting->forceFill(['source' => 'dataforseo', 'is_enabled' => true, 'secret_encrypted' => 'login@example.com:pa55word'])->save();

    startRun($t)->assertStatus(202);

    expect($calls)->toHaveCount(2);
    $kw = collect($this->actingAs($t['owner'])->getJson(researchUrl($t, 'keywords').'?q=tai%20nghe&per_page=100')->json('data'))->firstWhere('keyword', 'tai nghe');
    expect($kw['metrics']['search_volume'])->toBe(49500)
        ->and($kw['metrics']['difficulty'])->toBe(38)
        ->and($kw['metrics']['provider']['source'])->toBe('dataforseo')
        ->and($kw['metrics']['provider']['collected_at'])->not->toBeNull()
        ->and($kw['sources'])->toContain('dataforseo');

    // Keywords the provider had no number for stay empty (no guessing).
    $other = collect($this->actingAs($t['owner'])->getJson(researchUrl($t, 'keywords').'?per_page=100')->json('data'))->firstWhere('keyword', 'laptop');
    expect($other['metrics']['search_volume'])->toBeNull();
});

it('does not create duplicate topics when research runs again', function () {
    $t = researchTenant();
    startRun($t)->assertStatus(202);
    $before = inResearchTenant($t, fn () => ContentIdea::query()->pluck('fingerprint'));

    $second = startRun($t)->assertStatus(202)->json('data.research_run.id');

    $after = inResearchTenant($t, fn () => ContentIdea::query()->pluck('fingerprint'));
    expect($after->count())->toBe($after->unique()->count())
        ->and($after->count())->toBe($before->count())
        ->and(runDetail($t, $second)['stats']['ideas_created'])->toBe(0);
    $keywords = inResearchTenant($t, fn () => Keyword::query()->pluck('fingerprint'));
    expect($keywords->count())->toBe($keywords->unique()->count());
});

it('does not duplicate ideas when a step is replayed after a retry', function () {
    $t = researchTenant();
    $runId = startRun($t)->json('data.research_run.id');
    $count = inResearchTenant($t, fn () => ContentIdea::query()->count());

    // Replay the save of the same candidate groups (what a retried step does).
    inResearchTenant($t, function () use ($t, $runId) {
        $planner = app(IdeaPlanner::class);
        $groups = $planner->candidates($t['campaign']->fresh(), 50);
        $planner->save($t['campaign']->fresh(), $runId, $groups, []);
        $planner->save($t['campaign']->fresh(), $runId, $groups, []);
    });

    expect(inResearchTenant($t, fn () => ContentIdea::query()->count()))->toBe($count);
});

it('replays the same run for the same Idempotency-Key', function () {
    $t = researchTenant();
    $a = startRun($t, 'same-key-123')->assertStatus(202)->json('data.research_run.id');
    $b = startRun($t, 'same-key-123')->assertOk()->json('data.research_run.id');

    expect($b)->toBe($a)->and(inResearchTenant($t, fn () => ResearchRun::query()->count()))->toBe(1);
});

it('enforces the monthly research quota and the concurrency limit', function () {
    $t = researchTenant();
    $limit = new ResearchLimit;
    $limit->forceFill(['scope_key' => 'ws:'.$t['workspace']->id, 'workspace_id' => $t['workspace']->id, 'max_campaigns' => 10, 'max_runs_per_month' => 1,
        'max_ideas_per_run' => 20, 'max_competitors_per_campaign' => 5, 'max_calendar_items_per_month' => 60, 'max_concurrent_runs' => 1,
        'max_ai_cost_per_run_micro_usd' => 1_000_000])->save();

    startRun($t)->assertStatus(202);
    startRun($t)->assertStatus(429)->assertJsonPath('error.code', 'research_quota_exceeded');

    $usage = $this->actingAs($t['owner'])->getJson("/api/v1/workspaces/{$t['workspace']->id}/research/usage")->assertOk();
    expect($usage->json('data.limits.max_runs_per_month'))->toBe(1);
});

it('rejects a second run while one is still active on the campaign', function () {
    $t = researchTenant();
    inResearchTenant($t, function () use ($t) {
        $r = new ResearchRun;
        $r->forceFill(['campaign_id' => $t['campaign']->id, 'status' => 'running', 'trigger' => 'manual'])->save();
    });

    startRun($t)->assertStatus(409)->assertJsonPath('error.code', 'research_in_progress');
});

it('keeps AI spend under the per-run budget and still produces rule-based ideas', function () {
    $t = researchTenant();
    inResearchTenant($t, fn () => $t['campaign']->settingsOrDefault()->forceFill(['max_ai_cost_per_run_micro_usd' => 0])->save());

    $run = runDetail($t, startRun($t)->json('data.research_run.id'));

    expect($run['status'])->toBe('partial')
        ->and($run['warnings'])->toContain('ai_cost_cap_reached')
        ->and($run['cost_micro_usd'])->toBe(0)
        ->and($run['stats']['ideas_rule'])->toBeGreaterThan(0)
        ->and($run['stats']['ideas_ai'])->toBe(0);
});

it('works without Search Console and says so', function () {
    $t = researchTenant();
    $run = runDetail($t, startRun($t)->json('data.research_run.id'));

    $gsc = collect($run['sources'])->firstWhere('type', 'search_console');
    expect($run['status'])->toBe('succeeded')
        ->and($gsc['status'])->toBe('skipped')
        ->and($gsc['error']['code'])->toBe('not_connected');
    $this->actingAs($t['owner'])->getJson(researchUrl($t, 'search-console'))->assertOk()->assertJsonPath('data.connected', false);
});

it('continues with deterministic ideas when the AI provider fails', function () {
    $t = researchTenant('Tech Store', [], 'Bán lẻ đồ công nghệ [fail:auth]');

    $run = runDetail($t, startRun($t)->json('data.research_run.id'));

    expect($run['status'])->toBe('partial')
        ->and(collect($run['warnings'])->filter(fn ($w) => str_starts_with($w, 'ai_ideas_failed:')))->not->toBeEmpty()
        ->and($run['stats']['ideas_rule'])->toBeGreaterThan(0);
});

it('uses AI only to phrase evidence-backed ideas and rejects off-topic output', function () {
    $t = researchTenant();
    app()->instance(ResearchAi::class, new class(app(AiGateway::class)) extends ResearchAi
    {
        public function ideas(array $context, array $groups, string $callKey, ?string $runId): array
        {
            $ideas = [];
            foreach ($groups as $g) {
                $ideas[] = $g['group'] === 1
                    ? ['group' => 1, 'title' => 'Công thức nấu phở bò', 'content_type' => 'tutorial', 'audience' => 'x', 'angle' => 'x', 'rationale' => 'x', 'outline' => []]
                    : ['group' => $g['group'], 'title' => 'Kinh nghiệm chọn '.$g['primary_keyword'].' cho người mới', 'content_type' => $g['suggested_content_type'],
                        'audience' => 'Sinh viên', 'angle' => 'Thực tế', 'rationale' => 'Dựa trên danh mục', 'outline' => ['Mở đầu', 'Tiêu chí']];
            }

            return ['json' => ['ideas' => $ideas], 'cost_micro_usd' => 1200, 'model' => 'fake/fake-echo'];
        }
    });

    $run = runDetail($t, startRun($t)->json('data.research_run.id'));
    $titles = inResearchTenant($t, fn () => ContentIdea::query()->pluck('title'));

    expect($titles)->not->toContain('Công thức nấu phở bò')
        ->and($run['stats']['ideas_ai'])->toBeGreaterThan(0)
        ->and($run['stats']['ideas_rule'])->toBeGreaterThan(0)
        ->and($run['cost_micro_usd'])->toBeGreaterThanOrEqual(1200);
});

it('still plans from the synced WordPress index when the site is offline', function () {
    $t = researchTenant('Tech Store', ['offline' => true]);

    $run = runDetail($t, startRun($t)->json('data.research_run.id'));

    expect($run['status'])->toBe('partial')
        ->and(collect($run['warnings'])->first(fn ($w) => str_starts_with($w, 'site_unreachable')))->not->toBeNull()
        ->and($run['stats']['ideas_created'])->toBeGreaterThan(0);
    $home = inResearchTenant($t, fn () => ResearchSource::query()->where('type', 'website_crawl')->where('label', 'Trang chủ')->first());
    expect($home->status)->toBe('failed')->and($home->error_code)->toBe('unreachable');
});

it('respects robots.txt for the customer site', function () {
    $t = researchTenant('Blocked Store', ['pages' => ['https://blocked-store.example.vn/robots.txt' => "User-agent: *\nDisallow: /\n"]]);

    $run = runDetail($t, startRun($t)->json('data.research_run.id'));
    $home = collect($run['sources'])->first(fn ($s) => $s['type'] === 'website_crawl' && $s['label'] === 'Trang chủ');

    expect($home['status'])->toBe('blocked')->and($home['robots_allowed'])->toBeFalse();
    Http::assertNotSent(fn (HttpRequest $r) => $r->url() === 'https://blocked-store.example.vn/');
});

it('lets a viewer read research but not start or change it', function () {
    $t = researchTenant();

    $this->actingAs($t['viewer'])->getJson(researchUrl($t, 'keywords'))->assertOk();
    $this->actingAs($t['viewer'])->postJson(researchUrl($t, 'runs'))->assertForbidden();
    $this->actingAs($t['viewer'])->postJson(researchUrl($t, 'keywords'), ['keyword' => 'sạc dự phòng'])->assertForbidden();
    $this->actingAs($t['editor'])->postJson(researchUrl($t, 'keywords'), ['keyword' => 'sạc dự phòng'])->assertCreated();
    $this->actingAs($t['editor'])->deleteJson(researchUrl($t))->assertForbidden();
});
