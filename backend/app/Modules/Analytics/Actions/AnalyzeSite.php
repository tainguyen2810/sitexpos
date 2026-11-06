<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Actions;

use Carbon\CarbonImmutable;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\ContentDecayEvent;
use SiteXPost\Modules\Analytics\Domain\ContentPerformanceSnapshot;
use SiteXPost\Modules\Analytics\Domain\SearchPageMetric;
use SiteXPost\Modules\Analytics\Domain\SearchQueryPageMetric;
use SiteXPost\Modules\Analytics\Domain\SeoOpportunity;
use SiteXPost\Modules\Analytics\Domain\SeoRecommendation;
use SiteXPost\Modules\Analytics\Domain\UrlMapping;
use SiteXPost\Modules\Analytics\Engine\DecayDetector;
use SiteXPost\Modules\Analytics\Engine\Metrics;
use SiteXPost\Modules\Analytics\Engine\OpportunityRules;
use SiteXPost\Modules\Analytics\Engine\RecommendationEngine;
use SiteXPost\Modules\Analytics\Engine\TrendClassifier;
use SiteXPost\Modules\Analytics\Support\AnalyticsConfig;
use SiteXPost\Modules\Analytics\Support\SiteDataset;
use SiteXPost\Modules\Analytics\Support\UrlNormalizer;
use SiteXPost\Modules\Publishing\Contracts\PublicationDirectory;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

/**
 * Analysis layer for one site: performance snapshots + classification,
 * decay events, opportunities, recommendations and the health score.
 * Reads metric tables and read-only contracts; writes only Analytics
 * tables. Never changes the website.
 */
final class AnalyzeSite
{
    public function __construct(
        private readonly SiteDataset $dataset,
        private readonly PublicationDirectory $publications,
        private readonly SiteDirectory $sites,
        private readonly ComputeHealthScore $health,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{period_end: string|null, snapshots: int, decay: int, opportunities: int, recommendations: int, health: int|null}
     */
    public function run(string $siteId): array
    {
        $ws = $this->context->workspaceId();
        $t = AnalyticsConfig::thresholds($ws);
        $days = (int) $t['window_days'];
        $endDate = AnalyticsProperty::query()->where('site_id', $siteId)->where('provider', 'gsc')->value('last_complete_date')
            ?? SearchPageMetric::query()->where('site_id', $siteId)->max('date');
        $healthScore = null;
        if ($endDate === null) {
            $h = $this->health->run($siteId, CarbonImmutable::now('UTC')->startOfDay());

            return ['period_end' => null, 'snapshots' => 0, 'decay' => 0, 'opportunities' => 0, 'recommendations' => 0, 'health' => $h->score];
        }
        $end = CarbonImmutable::parse((string) $endDate)->startOfDay();

        $snapshots = $this->snapshots($siteId, $end, $days, $t);
        $decay = $this->decay($siteId, $end, $days, $t);
        [$opps, $recs] = $this->opportunities($siteId, $end, $days, $t);
        $healthScore = $this->health->run($siteId, $end)->score;

        return ['period_end' => $end->toDateString(), 'snapshots' => $snapshots, 'decay' => $decay, 'opportunities' => $opps, 'recommendations' => $recs, 'health' => $healthScore];
    }

    /**
     * @param  array<string, float|int>  $t
     */
    private function snapshots(string $siteId, CarbonImmutable $end, int $days, array $t): int
    {
        $pages = $this->dataset->pages($siteId, $end, $days);
        $pubDates = [];
        foreach ($this->publications->forSite($siteId) as $p) {
            if ($p['last_published_at'] !== null) {
                $pubDates[$p['article_id']]['last'] = max($pubDates[$p['article_id']]['last'] ?? '', $p['last_published_at']);
            }
        }
        $inventory = [];
        foreach ($this->sites->content($siteId, ['post', 'page', 'product'], 20000) as $i) {
            if ($i['url'] !== null) {
                $inventory[sha1(UrlNormalizer::normalize($i['url']))] = $i['published_at'];
            }
        }
        $modified = [];
        foreach ($this->sites->contentIndex($siteId, ['post', 'page', 'product'], 20000) as $i) {
            if ($i['url'] !== null && $i['modified_at'] !== null) {
                $modified[sha1(UrlNormalizer::normalize($i['url']))] = $i['modified_at'];
            }
        }
        $list = [];
        foreach ($pages as $hash => $p) {
            $w = $p['windows'];
            $list[] = ['recent' => $w['recent']];
        }
        $medians = $this->dataset->bandMedians($list, (int) $t['min_impressions']);
        $count = 0;
        foreach ($pages as $hash => $p) {
            $w = $p['windows'];
            if ($w['recent']['impressions'] === 0 && $w['previous']['impressions'] === 0) {
                continue;
            }
            $published = $inventory[$hash] ?? null;
            $age = $published !== null ? (int) CarbonImmutable::parse($published)->diffInDays($end) : null;
            $updated = $modified[$hash] ?? ($p['article_id'] !== null ? ($pubDates[$p['article_id']]['last'] ?? null) : null);
            $sinceUpdate = $updated !== null ? (int) CarbonImmutable::parse($updated)->diffInDays($end) : null;
            $baseline = array_values(array_filter([$w['base1']['clicks'], $w['base2']['clicks'], $w['base3']['clicks']], fn ($c, $i) => [$w['base1'], $w['base2'], $w['base3']][$i]['days'] > 0, ARRAY_FILTER_USE_BOTH));
            $baseMean = $baseline !== [] ? array_sum($baseline) / count($baseline) : null;
            $res = TrendClassifier::classify(['clicks' => $w['recent']['clicks'], 'impressions' => $w['recent']['impressions'], 'position' => $w['recent']['position'], 'days_with_data' => $w['recent']['days']],
                $w['previous'], $t, $baseMean, $medians[Metrics::positionBand($w['recent']['position'])] ?? null, $age, $sinceUpdate);
            $snap = (ContentPerformanceSnapshot::query()->where(['site_id' => $siteId, 'page_hash' => $hash, 'period_end' => $end->toDateString(), 'window_days' => $days])->first() ?? (new ContentPerformanceSnapshot)->forceFill(['site_id' => $siteId, 'page_hash' => $hash, 'period_end' => $end->toDateString(), 'window_days' => $days]));
            $snap->forceFill([
                'site_id' => $siteId, 'page_hash' => $hash, 'page_url' => (string) $p['page_url'], 'article_id' => $p['article_id'], 'period_end' => $end->toDateString(), 'window_days' => $days,
                'clicks' => $w['recent']['clicks'], 'impressions' => $w['recent']['impressions'], 'position' => $w['recent']['position'],
                'prev_clicks' => $w['previous']['clicks'], 'prev_impressions' => $w['previous']['impressions'], 'prev_position' => $w['previous']['position'],
                'baseline_clicks' => $baseMean, 'yoy_clicks' => $w['ly_recent']['days'] > 0 ? $w['ly_recent']['clicks'] : null, 'days_with_data' => $w['recent']['days'],
                'classification' => $res['classification'], 'labels' => $res['labels'], 'reasons' => $res['reasons'],
                'published_at' => $published !== null ? CarbonImmutable::parse($published) : null,
            ])->save();
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, float|int>  $t
     */
    private function decay(string $siteId, CarbonImmutable $end, int $days, array $t): int
    {
        $pages = $this->dataset->pages($siteId, $end, $days);
        $periods = SearchQueryPageMetric::query()->where('site_id', $siteId)->distinct()->orderByDesc('period_end')->limit(2)->pluck('period_end')->all();
        $urlsByArticle = UrlMapping::query()->where('site_id', $siteId)->whereNotNull('article_id')->get(['article_id', 'page_url'])->groupBy('article_id');
        $pubs = collect($this->publications->forSite($siteId))->groupBy('article_id');
        $recentStart = $end->subDays(2 * $days - 1);
        $count = 0;
        foreach ($pages as $hash => $p) {
            $w = $p['windows'];
            $baseline = [];
            foreach (['base1', 'base2', 'base3'] as $b) {
                if ($w[$b]['days'] > 0) {
                    $baseline[] = $w[$b]['clicks'];
                }
            }
            $lastYear = $w['ly_recent']['days'] >= 14 && $w['ly_previous']['days'] >= 14 ? ['recent' => $w['ly_recent']['clicks'], 'previous' => $w['ly_previous']['clicks']] : null;
            $queryChanges = ['lost' => [], 'gained' => []];
            if (count($periods) === 2) {
                $now = SearchQueryPageMetric::query()->where('site_id', $siteId)->where('page_hash', $hash)->where('period_end', $periods[0])->pluck('clicks', 'query')->all();
                $before = SearchQueryPageMetric::query()->where('site_id', $siteId)->where('page_hash', $hash)->where('period_end', $periods[1])->pluck('clicks', 'query')->all();
                foreach ($before as $q => $c) {
                    if ($c > 0 && ! isset($now[$q])) {
                        $queryChanges['lost'][] = ['query' => $q, 'clicks_before' => (int) $c];
                    }
                }
                foreach ($now as $q => $c) {
                    if ($c > 0 && ! isset($before[$q])) {
                        $queryChanges['gained'][] = ['query' => $q, 'clicks' => (int) $c];
                    }
                }
            }
            $urlChanges = [];
            $contentChanges = [];
            if ($p['article_id'] !== null) {
                $urls = ($urlsByArticle[$p['article_id']] ?? collect())->pluck('page_url')->unique()->values()->all();
                $urlChanges = count($urls) > 1 ? $urls : [];
                foreach ($pubs[$p['article_id']] ?? [] as $pub) {
                    if ($pub['last_published_at'] !== null && CarbonImmutable::parse($pub['last_published_at'])->greaterThanOrEqualTo($recentStart)) {
                        $contentChanges[] = substr((string) $pub['last_published_at'], 0, 10);
                    }
                }
            }
            $res = DecayDetector::evaluate($w['recent'], $w['previous'], $baseline, $lastYear, $queryChanges, $urlChanges, $contentChanges, $t);
            $open = ContentDecayEvent::query()->where('site_id', $siteId)->where('open_key', $hash)->first();
            if ($res === null) {
                if ($open !== null && in_array($open->status, [DecayDetector::SUSPECTED, DecayDetector::INVESTIGATE], true)) {
                    $open->forceFill(['status' => DecayDetector::NO_ACTION, 'open_key' => null, 'decision_note' => 'Tự đóng: hiệu quả đã trở lại mức bình thường.', 'last_evaluated_at' => now()])->save();
                }

                continue;
            }
            $e = $open ?? new ContentDecayEvent;
            $fill = ['site_id' => $siteId, 'page_hash' => $hash, 'page_url' => (string) $p['page_url'], 'article_id' => $p['article_id'], 'open_key' => $hash,
                'decline_pct' => $res['decline_pct'], 'recent' => $w['recent'], 'previous' => $w['previous'], 'baseline' => $res['baseline'], 'seasonal' => $res['seasonal'],
                'query_changes' => ['lost' => array_slice($queryChanges['lost'], 0, 20), 'gained' => array_slice($queryChanges['gained'], 0, 20)],
                'url_changes' => $urlChanges, 'content_changes' => $contentChanges, 'signals' => $res['signals'], 'last_evaluated_at' => now()];
            if ($open === null) {
                $fill += ['status' => $res['status'], 'detected_at' => now()];
            } elseif (in_array($open->status, [DecayDetector::SUSPECTED, DecayDetector::INVESTIGATE], true)) {
                $fill['status'] = $res['status']; // human decisions (CONFIRMED_ISSUE) are never overwritten
            }
            $e->forceFill($fill)->save();
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, float|int>  $t
     * @return array{0: int, 1: int}
     */
    private function opportunities(string $siteId, CarbonImmutable $end, int $days, array $t): array
    {
        $data = $this->dataset->build($siteId, $end, $days);
        $now = now();
        $seen = [];
        foreach (OpportunityRules::detect($data, $t) as $c) {
            $seen[] = $c['fingerprint'];
            $o = SeoOpportunity::query()->where('site_id', $siteId)->where('fingerprint', $c['fingerprint'])->first();
            $cost = AnalyticsConfig::ACTION_COST[$c['recommended_action']] ?? 0;
            $fill = ['site_id' => $siteId, 'estimated_ai_cost_micro_usd' => $cost, 'last_detected_at' => $now] + $c;
            if ($o === null) {
                $o = new SeoOpportunity;
                $fill += ['status' => 'open', 'first_detected_at' => $now, 'detections' => 1];
            } else {
                $fill['detections'] = $o->detections + 1;
                if (in_array($o->status, ['resolved', 'expired'], true)) {
                    $fill['status'] = 'open';
                }
            }
            $o->forceFill($fill)->save();
        }
        // Opportunities not seen for 14 days expire (data changed); dismissed ones stay dismissed.
        SeoOpportunity::query()->where('site_id', $siteId)->where('status', 'open')->where('last_detected_at', '<', $now->copy()->subDays(14))->update(['status' => 'expired']);

        $recs = 0;
        foreach (SeoOpportunity::query()->where('site_id', $siteId)->whereIn('status', ['open', 'accepted'])->whereIn('fingerprint', $seen)->get() as $o) {
            $rec = RecommendationEngine::fromOpportunity($o->toArray(), $o->estimated_ai_cost_micro_usd);
            if ($rec === null || SeoRecommendation::query()->where('site_id', $siteId)->where('fingerprint', $rec['fingerprint'])->exists()) {
                continue;
            }
            $r = new SeoRecommendation;
            $r->forceFill($rec + ['site_id' => $siteId, 'opportunity_id' => $o->id, 'status' => 'proposed', 'source' => 'rule'])->save();
            $recs++;
        }

        return [count($seen), $recs];
    }
}
