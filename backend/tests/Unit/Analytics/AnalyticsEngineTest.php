<?php

use SiteXPost\Modules\Analytics\Engine\DecayDetector;
use SiteXPost\Modules\Analytics\Engine\ExperimentEvaluator;
use SiteXPost\Modules\Analytics\Engine\HealthScorer;
use SiteXPost\Modules\Analytics\Engine\Metrics;
use SiteXPost\Modules\Analytics\Engine\OpportunityRules;
use SiteXPost\Modules\Analytics\Engine\TrendClassifier;
use SiteXPost\Modules\Analytics\Support\AnalyticsConfig;
use SiteXPost\Modules\Analytics\Support\UrlNormalizer;

$t = AnalyticsConfig::THRESHOLDS;

it('aggregates like Search Console: CTR from sums, impression-weighted position', function () {
    $agg = Metrics::aggregate([
        ['clicks' => 10, 'impressions' => 100, 'position' => 2.0],
        ['clicks' => 0, 'impressions' => 300, 'position' => 10.0],
    ]);
    expect($agg)->toBe(['clicks' => 10, 'impressions' => 400, 'ctr' => 2.5, 'position' => 8.0])
        ->and(Metrics::ctr(0, 0))->toBeNull()
        ->and(Metrics::change(50, 0))->toBeNull() // no "infinite growth"
        ->and(Metrics::change(75, 100))->toBe(-25.0)
        ->and(Metrics::positionBand(3.0))->toBe('1-3')
        ->and(Metrics::positionBand(null))->toBe('none');
});

it('classifies content with minimum data, recent publication and configurable thresholds', function () use ($t) {
    $r = fn ($c, $i, $p = 8.0, $d = 28) => ['clicks' => $c, 'impressions' => $i, 'position' => $p, 'days_with_data' => $d];
    $p = fn ($c, $i, $pos = 8.0) => ['clicks' => $c, 'impressions' => $i, 'position' => $pos];

    expect(TrendClassifier::classify($r(5, 50), $p(5, 50), $t)['classification'])->toBe(TrendClassifier::INSUFFICIENT_DATA)
        ->and(TrendClassifier::classify($r(200, 4000), $p(100, 3000), $t, ageDays: 10)['classification'])->toBe(TrendClassifier::RECENTLY_PUBLISHED)
        ->and(TrendClassifier::classify($r(200, 4000), $p(100, 3000), $t)['classification'])->toBe(TrendClassifier::GROWING)
        ->and(TrendClassifier::classify($r(50, 2500), $p(100, 3000), $t)['classification'])->toBe(TrendClassifier::DECLINING)
        ->and(TrendClassifier::classify($r(100, 3000), $p(98, 3000), $t)['classification'])->toBe(TrendClassifier::STABLE);

    $res = TrendClassifier::classify($r(50, 2500), $p(100, 3000), $t);
    expect($res['reasons'])->not->toBeEmpty(); // every class is explained

    // Thresholds are configurable: a stricter "declining" threshold changes the result.
    $loose = ['declining_pct' => -60.0] + $t;
    expect(TrendClassifier::classify($r(50, 2500), $p(100, 3000), $loose)['classification'])->not->toBe(TrendClassifier::DECLINING);
});

it('detects decay only with enough history and discounts spikes and seasonality', function () use ($t) {
    $w = fn ($c, $i = 2000) => ['clicks' => $c, 'impressions' => $i, 'position' => 6.0];
    $none = ['lost' => [], 'gained' => []];

    expect(DecayDetector::evaluate($w(5), $w(10), [], null, $none, [], [], $t))->toBeNull(); // too little history
    expect(DecayDetector::evaluate($w(90), $w(100), [100, 100], null, $none, [], [], $t))->toBeNull(); // -10% is not decay

    $res = DecayDetector::evaluate($w(50), $w(100), [100, 105, 98], null, $none, [], [], $t);
    expect($res)->not->toBeNull()
        ->and($res['status'])->toBeIn(DecayDetector::STATUSES)
        ->and($res['decline_pct'])->toBe(-50.0)
        ->and($res['signals'])->not->toBeEmpty();

    // Previous window was a spike: compared with the long-run baseline the page is normal.
    expect(DecayDetector::evaluate($w(50), $w(100), [50, 52, 48], null, $none, [], [], $t))->toBeNull();

    // Same drop last year → seasonal context is recorded and the event is not "confirmed".
    $seasonal = DecayDetector::evaluate($w(50), $w(100), [100, 100, 100], ['recent' => 52, 'previous' => 100], $none, [], [], $t);
    expect($seasonal['seasonal'])->not->toBeNull()->and($seasonal['status'])->not->toBe(DecayDetector::CONFIRMED);
});

it('scores SEO health only from available components and never invents a value', function () {
    $w = AnalyticsConfig::HEALTH_WEIGHTS;
    $none = HealthScorer::score([], $w);
    expect($none['score'])->toBeNull()->and($none['components']['broken_links']['available'])->toBeFalse();

    $s = HealthScorer::score(['content_freshness' => ['value' => 80.0, 'detail' => 'x'], 'broken_links' => ['value' => 40.0, 'detail' => 'y']], $w);
    // (80*20 + 40*15) / 35 = 62.86
    expect($s['score'])->toBe(63);
});

it('evaluates experiments as before/after with a minimum data rule and site context', function () {
    $days = fn (int $n, int $c, int $i, string $from) => array_map(fn ($k) => ['date' => date('Y-m-d', strtotime($from." +{$k} day")), 'clicks' => $c, 'impressions' => $i, 'position' => 5.0], range(0, $n - 1));

    expect(ExperimentEvaluator::evaluate($days(10, 5, 100, '2026-01-01'), $days(10, 9, 100, '2026-02-01'))['outcome'])->toBe('insufficient_data');
    $res = ExperimentEvaluator::evaluate($days(28, 10, 200, '2026-01-01'), $days(28, 14, 210, '2026-02-01'), ['baseline_clicks' => 1000, 'observation_clicks' => 1000]);
    expect($res['outcome'])->toBe('improved')
        ->and($res['observed']['clicks_change_pct'])->toBe(40.0)
        ->and($res['observed']['relative_to_site_pts'])->toBe(40.0)
        ->and($res['note'])->toContain('không phải bằng chứng nhân quả');
    expect(ExperimentEvaluator::evaluate($days(28, 10, 200, '2026-01-01'), $days(28, 10, 200, '2026-02-01'))['outcome'])->toBe('no_clear_change');
});

it('normalizes URLs for exact mapping without guessing', function () {
    expect(UrlNormalizer::normalize('HTTPS://Blog.Example.com/Bai-Viet/?utm_source=x#top'))->toBe(UrlNormalizer::normalize('https://blog.example.com/Bai-Viet/'))
        ->and(UrlNormalizer::hash('https://blog.example.com/a/'))->not->toBe(UrlNormalizer::hash('https://blog.example.com/b/'))
        ->and(UrlNormalizer::host('https://www.blog.example.com/x'))->toContain('blog.example.com');
});

it('does not call content orphaned when the content of the other articles is unknown', function () {
    $orphan = new ReflectionMethod(OpportunityRules::class, 'orphanContent');
    $article = fn (int $i, string $text, array $links = []) => ['article_id' => 'a'.$i, 'title' => 'Bài '.$i, 'url' => "https://b.example/bai-$i/", 'text' => $text, 'links_norm' => $links];
    $unknown = ['managed' => array_map(fn ($i) => $article($i, ''), range(1, 5))];
    expect($orphan->invoke(null, $unknown, []))->toBe([]);

    $linksTo = fn (int $i) => [UrlNormalizer::normalize("https://b.example/bai-$i/")];
    $known = ['managed' => [$article(1, 'x', $linksTo(2)), $article(2, 'x', $linksTo(3)), $article(3, 'x', $linksTo(1)), $article(4, 'x', $linksTo(1)), $article(5, 'x')]];
    $found = $orphan->invoke(null, $known, []);
    expect(array_column($found, 'target_ref'))->toEqualCanonicalizing(['a4', 'a5']);
});
