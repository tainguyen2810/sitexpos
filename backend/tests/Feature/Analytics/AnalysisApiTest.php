<?php

use SiteXPost\Modules\Analytics\Domain\ContentDecayEvent;
use SiteXPost\Modules\Analytics\Domain\ContentPerformanceSnapshot;
use SiteXPost\Modules\Analytics\Domain\SeoHealthScore;
use SiteXPost\Modules\Analytics\Domain\SeoOpportunity;
use SiteXPost\Modules\Analytics\Domain\SeoRecommendation;
use SiteXPost\Modules\Analytics\Jobs\AnalyzeSiteJob;
use SiteXPost\Modules\Analytics\Support\UrlNormalizer;

require_once __DIR__.'/helpers.php';

/**
 * Three pages over 120 days: one declining, one growing, one with many
 * impressions near the top but almost no clicks.
 */
function analysedTenant(): array
{
    $t = analyticsTenant();
    seedMetrics($t['workspace'], $t['site'], [
        ['url' => 'https://b.example/giam/', 'query' => 'bài đang giảm', 'clicks' => fn ($i) => $i < 28 ? 20 : 60, 'impressions' => fn ($i) => $i < 28 ? 1500 : 3000, 'position' => 6.0],
        ['url' => 'https://b.example/tang/', 'query' => 'bài đang tăng', 'clicks' => fn ($i) => $i < 28 ? 80 : 30, 'impressions' => fn ($i) => $i < 28 ? 3000 : 1500, 'position' => 4.0],
        ['url' => 'https://b.example/ctr-thap/', 'query' => 'ctr thấp', 'clicks' => fn ($i) => $i % 10 === 0 ? 1 : 0, 'impressions' => fn ($i) => 400, 'position' => 3.5],
    ]);
    inAnalyticsTenant($t, fn () => AnalyzeSiteJob::dispatch($t['site']->id));

    return $t;
}

it('classifies content, detects decay and explainable opportunities from synced metrics', function () {
    $t = analysedTenant();
    $snaps = inAnalyticsTenant($t, fn () => ContentPerformanceSnapshot::query()->get()->keyBy('page_hash'));
    expect($snaps[UrlNormalizer::hash('https://b.example/giam/')]->classification)->toBe('DECLINING')
        ->and($snaps[UrlNormalizer::hash('https://b.example/tang/')]->classification)->toBe('GROWING')
        ->and($snaps[UrlNormalizer::hash('https://b.example/giam/')]->reasons)->not->toBeEmpty();

    $decay = inAnalyticsTenant($t, fn () => ContentDecayEvent::query()->get());
    expect($decay->pluck('page_url')->all())->toContain(UrlNormalizer::normalize('https://b.example/giam/'))
        ->and($decay->first()->status)->toBeIn(['DECAY_SUSPECTED', 'NEEDS_INVESTIGATION', 'CONFIRMED_ISSUE'])
        ->and($decay->first()->signals)->not->toBeEmpty();

    $opps = inAnalyticsTenant($t, fn () => SeoOpportunity::query()->get());
    expect($opps->pluck('rule_code')->all())->toContain('query_high_impr_low_ctr')->toContain('page_traffic_decline');
    foreach ($opps as $o) {
        // Every opportunity carries evidence, its rule, severity, impact basis, confidence and an action.
        expect($o->evidence)->not->toBeEmpty()->and($o->detection_rule)->not->toBe('')->and($o->impact_basis)->not->toBe('')
            ->and($o->confidence)->toBeGreaterThan(0)->and($o->recommended_action)->not->toBe('')->and($o->status)->toBe('open');
    }
    expect(inAnalyticsTenant($t, fn () => SeoRecommendation::query()->count()))->toBeGreaterThan(0)
        ->and(inAnalyticsTenant($t, fn () => SeoHealthScore::query()->count()))->toBe(1);

    // Re-running the analysis does not duplicate opportunities (fingerprint) or open decay events.
    inAnalyticsTenant($t, fn () => AnalyzeSiteJob::dispatch($t['site']->id));
    expect(inAnalyticsTenant($t, fn () => SeoOpportunity::query()->count()))->toBe($opps->count())
        ->and(inAnalyticsTenant($t, fn () => ContentDecayEvent::query()->whereNotNull('open_key')->count()))->toBe($decay->count());
});

it('serves the dashboard with filters, comparisons and the Search Console caveat to every member', function () {
    $t = analysedTenant();
    $site = $t['site']->id;
    $res = $this->actingAs($t['viewer'])->getJson(anUrl($t['workspace'], "analytics/overview?site_id={$site}"))->assertOk();
    expect($res->json('data.has_data'))->toBeTrue()
        ->and($res->json('data.totals.clicks'))->toBeGreaterThan(0)
        ->and($res->json('data.series'))->toHaveCount(28)
        ->and($res->json('meta.note'))->toContain('độ trễ');

    $q = $this->actingAs($t['viewer'])->getJson(anUrl($t['workspace'], "analytics/queries?site_id={$site}&sort=impressions&q=giảm"))->assertOk()->json('data');
    expect($q)->toHaveCount(1)->and($q[0]['label'])->toBe('bài đang giảm')->and($q[0]['clicks_change'])->toBe(-66.7);

    $this->actingAs($t['viewer'])->getJson(anUrl($t['workspace'], "analytics/pages?site_id={$site}&sort=ctr"))->assertOk()->assertJsonCount(3, 'data');
    $this->actingAs($t['viewer'])->getJson(anUrl($t['workspace'], "analytics/dimensions?site_id={$site}&by=device"))->assertOk()->assertJsonPath('data.0.value', 'mobile');
    $this->actingAs($t['viewer'])->getJson(anUrl($t['workspace'], "analytics/content?site_id={$site}"))->assertOk()->assertJsonPath('meta.counts.DECLINING', 1);
    $this->actingAs($t['viewer'])->getJson(anUrl($t['workspace'], "analytics/health?site_id={$site}"))->assertOk()
        ->assertJsonPath('data.disclaimer', __('analytics.labels.health_disclaimer'));
    $this->actingAs($t['viewer'])->getJson(anUrl($t['workspace'], "analytics/overview?site_id={$site}&from=2026-01-10&to=2025-01-01"))->assertStatus(422);
    $this->actingAs($t['viewer'])->getJson(anUrl($t['workspace'], 'analytics/overview?site_id=01JUNKJUNKJUNKJUNKJUNKJUNK'))->assertNotFound();
});

it('lets editors review opportunities, decay and recommendations, while viewers only read', function () {
    $t = analysedTenant();
    $opp = inAnalyticsTenant($t, fn () => SeoOpportunity::query()->where('rule_code', 'query_high_impr_low_ctr')->firstOrFail());
    $this->actingAs($t['viewer'])->patchJson(anUrl($t['workspace'], "analytics/opportunities/{$opp->id}"), ['status' => 'dismissed'])->assertForbidden();
    $this->actingAs($t['viewer'])->getJson(anUrl($t['workspace'], "analytics/opportunities/{$opp->id}"))->assertOk();
    $this->actingAs($t['editor'])->patchJson(anUrl($t['workspace'], "analytics/opportunities/{$opp->id}"), ['status' => 'dismissed', 'note' => 'Đã biết'])
        ->assertOk()->assertJsonPath('data.status', 'dismissed');
    expect(inAnalyticsTenant($t, fn () => SeoRecommendation::query()->where('opportunity_id', $opp->id)->where('status', 'proposed')->count()))->toBe(0);

    $decay = inAnalyticsTenant($t, fn () => ContentDecayEvent::query()->firstOrFail());
    $this->actingAs($t['viewer'])->patchJson(anUrl($t['workspace'], "analytics/decay/{$decay->id}"), ['status' => 'NO_ACTION'])->assertForbidden();
    $this->actingAs($t['editor'])->patchJson(anUrl($t['workspace'], "analytics/decay/{$decay->id}"), ['status' => 'NO_ACTION'])->assertOk();
    expect($decay->fresh()->open_key)->toBeNull();

    $rec = inAnalyticsTenant($t, fn () => SeoRecommendation::query()->where('status', 'proposed')->firstOrFail());
    $this->actingAs($t['viewer'])->patchJson(anUrl($t['workspace'], "analytics/recommendations/{$rec->id}"), ['status' => 'approved'])->assertForbidden();
    $this->actingAs($t['editor'])->patchJson(anUrl($t['workspace'], "analytics/recommendations/{$rec->id}"), ['status' => 'approved'])->assertOk();
    $this->actingAs($t['editor'])->patchJson(anUrl($t['workspace'], "analytics/recommendations/{$rec->id}"), ['status' => 'applied'])->assertStatus(422);

    // Settings and sync need sites.manage; thresholds are validated and versioned.
    $this->actingAs($t['editor'])->putJson(anUrl($t['workspace'], 'analytics/settings'), ['lock_version' => 0, 'thresholds' => ['decay_pct' => 30]])->assertForbidden();
    $this->actingAs($t['admin'])->putJson(anUrl($t['workspace'], 'analytics/settings'), ['lock_version' => 0, 'thresholds' => ['decay_pct' => 30, 'evil' => 1]])->assertStatus(422);
    $this->actingAs($t['admin'])->putJson(anUrl($t['workspace'], 'analytics/settings'), ['lock_version' => 0, 'thresholds' => ['decay_pct' => 30]])->assertOk()
        ->assertJsonPath('data.thresholds.decay_pct', 30);
    $this->actingAs($t['admin'])->putJson(anUrl($t['workspace'], 'analytics/settings'), ['lock_version' => 0, 'thresholds' => ['decay_pct' => 31]])->assertStatus(409);
    $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/sync"))->assertForbidden();
});

it('tracks before/after experiments without calling them A/B tests', function () {
    $t = analysedTenant();
    $res = $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], 'analytics/experiments'), [
        'site_id' => $t['site']->id, 'page_url' => 'https://b.example/tang/', 'change_type' => 'seo_title', 'change_date' => now()->subDays(40)->toDateString(),
    ])->assertCreated();
    expect($res->json('data.method'))->toBe('before_after');
    inAnalyticsTenant($t, fn () => AnalyzeSiteJob::dispatch($t['site']->id)); // evaluation runs with the analysis
    $exp = $this->actingAs($t['viewer'])->getJson(anUrl($t['workspace'], 'analytics/experiments/'.$res->json('data.id')))->assertOk();
    expect($exp->json('data.status'))->toBeIn(['completed', 'insufficient_data', 'observing'])
        ->and($exp->json('data.observations'))->not->toBeEmpty();
    $this->actingAs($t['viewer'])->postJson(anUrl($t['workspace'], 'analytics/experiments'), ['site_id' => $t['site']->id, 'page_url' => 'https://b.example/x', 'change_type' => 'seo_title', 'change_date' => '2026-01-01'])->assertForbidden();
});
