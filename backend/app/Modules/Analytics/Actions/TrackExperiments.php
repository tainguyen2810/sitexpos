<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\ExperimentObservation;
use SiteXPost\Modules\Analytics\Domain\SearchPageMetric;
use SiteXPost\Modules\Analytics\Domain\SearchPerformanceDaily;
use SiteXPost\Modules\Analytics\Domain\SeoExperiment;
use SiteXPost\Modules\Analytics\Engine\ExperimentEvaluator;
use SiteXPost\Modules\Analytics\Support\UrlNormalizer;
use SiteXPost\Modules\Audit\Audit;

/**
 * Content experiment tracking: start a before/after observation when a
 * change goes live, record daily observations from Search Console data and
 * evaluate once the observation window has complete data.
 */
final class TrackExperiments
{
    public const CHANGE_TYPES = ['seo_title', 'meta_description', 'structure', 'internal_links', 'content_refresh', 'new_sections'];

    /**
     * @param  list<string>  $metrics
     */
    public function start(string $siteId, ?string $articleId, string $pageUrl, string $changeType, ?string $changeRef, Carbon|CarbonImmutable $changeDate, ?string $userId, array $metrics = ['clicks', 'impressions', 'ctr', 'position'], int $days = 28): SeoExperiment
    {
        $hash = UrlNormalizer::hash($pageUrl);
        $existing = $changeRef !== null ? SeoExperiment::query()->where('site_id', $siteId)->where('change_ref', $changeRef)->where('change_type', $changeType)->first() : null;
        if ($existing !== null) {
            return $existing;
        }
        $day = CarbonImmutable::parse($changeDate)->startOfDay();
        $e = new SeoExperiment;
        $e->forceFill([
            'site_id' => $siteId, 'article_id' => $articleId, 'page_hash' => $hash, 'page_url' => mb_substr(UrlNormalizer::normalize($pageUrl), 0, 1000),
            'change_type' => $changeType, 'change_ref' => $changeRef, 'method' => 'before_after', 'change_date' => CarbonImmutable::parse($changeDate),
            'baseline_start' => $day->subDays($days)->toDateString(), 'baseline_end' => $day->subDay()->toDateString(),
            'observation_days' => $days, 'observation_end' => $day->addDays($days)->toDateString(), 'target_metrics' => $metrics, 'status' => 'observing', 'created_by' => $userId,
        ])->save();
        Audit::record('analytics.experiment_started', $e, ['change_type' => $changeType, 'change_ref' => $changeRef]);

        return $e;
    }

    public function run(string $siteId): int
    {
        $last = AnalyticsProperty::query()->where('site_id', $siteId)->where('provider', 'gsc')->value('last_complete_date');
        $done = 0;
        foreach (SeoExperiment::query()->where('site_id', $siteId)->where('status', 'observing')->get() as $e) {
            $this->observe($e);
            if ($last !== null && (string) $last >= $e->observation_end) {
                $this->evaluate($e);
                $done++;
            }
        }

        return $done;
    }

    public function observe(SeoExperiment $e): void
    {
        $rows = SearchPageMetric::query()->where('site_id', $e->site_id)->where('page_hash', $e->page_hash)->where('search_type', 'web')
            ->whereBetween('date', [$e->baseline_start, $e->observation_end])->get(['date', 'clicks', 'impressions', 'position']);
        $changeDay = CarbonImmutable::parse($e->change_date)->toDateString();
        foreach ($rows as $r) {
            $date = substr((string) $r->date, 0, 10);
            if ($date === $changeDay) {
                continue; // the change day itself is mixed
            }
            $o = ExperimentObservation::query()->firstOrNew(['experiment_id' => $e->id, 'date' => $date]);
            $o->forceFill(['experiment_id' => $e->id, 'date' => $date, 'phase' => $date < $changeDay ? 'baseline' : 'observation',
                'clicks' => $r->clicks, 'impressions' => $r->impressions, 'position' => $r->position, 'is_demo' => $e->is_demo])->save();
        }
    }

    public function evaluate(SeoExperiment $e): SeoExperiment
    {
        $obs = ExperimentObservation::query()->where('experiment_id', $e->id)->orderBy('date')->get();
        $map = fn ($o) => ['date' => (string) $o->date, 'clicks' => $o->clicks, 'impressions' => $o->impressions, 'position' => $o->position];
        $site = SearchPerformanceDaily::query()->where('site_id', $e->site_id)->where('search_type', 'web')
            ->selectRaw('SUM(CASE WHEN date BETWEEN ? AND ? THEN clicks ELSE 0 END) as b, SUM(CASE WHEN date > ? AND date <= ? THEN clicks ELSE 0 END) as o',
                [$e->baseline_start, $e->baseline_end, CarbonImmutable::parse($e->change_date)->toDateString(), $e->observation_end])->toBase()->first();
        $res = ExperimentEvaluator::evaluate(
            $obs->where('phase', 'baseline')->values()->map($map)->all(),
            $obs->where('phase', 'observation')->values()->map($map)->all(),
            $site !== null ? ['baseline_clicks' => (int) $site->b, 'observation_clicks' => (int) $site->o] : null,
        );
        $e->forceFill(['baseline' => $res['baseline'], 'observed' => $res['observed'], 'outcome' => $res['outcome'], 'outcome_note' => $res['note'],
            'status' => $res['outcome'] === 'insufficient_data' ? 'insufficient_data' : 'completed'])->save();

        return $e;
    }
}
