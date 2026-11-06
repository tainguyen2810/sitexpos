<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\ContentPerformanceSnapshot;
use SiteXPost\Modules\Analytics\Domain\ContentRefreshJob;
use SiteXPost\Modules\Analytics\Domain\SearchPageMetric;
use SiteXPost\Modules\Analytics\Domain\SearchQueryPageMetric;
use SiteXPost\Modules\Analytics\Domain\SeoExperiment;
use SiteXPost\Modules\Analytics\Domain\SeoHealthScore;
use SiteXPost\Modules\Analytics\Domain\SeoOpportunity;
use SiteXPost\Modules\Analytics\Domain\UrlMapping;
use SiteXPost\Modules\Analytics\Engine\Metrics;
use SiteXPost\Modules\Analytics\Engine\TrendClassifier;
use SiteXPost\Modules\Analytics\Http\AnalyticsPresenter;
use SiteXPost\Modules\Analytics\Support\DashboardQuery;
use SiteXPost\Modules\Analytics\Support\SiteDataset;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * Analytics dashboard, article performance, content intelligence and SEO
 * health (read only). Search Console numbers are shown as reported — with
 * their delay and sampling caveat — never extrapolated.
 */
final class DashboardController
{
    public function __construct(private readonly DashboardQuery $dash, private readonly ArticleCatalog $articles) {}

    public function overview(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);
        $f = $this->dash->filters($request);
        $data = $this->dash->overview($f);
        $data['opportunities_open'] = SeoOpportunity::query()->where('status', 'open')->when($f['site_id'] !== null, fn ($q) => $q->where('site_id', $f['site_id']))->count();
        $data['health'] = $f['site_id'] !== null ? (fn ($h) => $h === null ? null : AnalyticsPresenter::health($h))(SeoHealthScore::query()->where('site_id', $f['site_id'])->orderByDesc('date')->first()) : null;
        $data['top_queries'] = array_slice($this->dash->queries(['limit' => 10] + $f), 0, 10);
        $data['top_pages'] = array_slice($this->dash->pages(['limit' => 10] + $f), 0, 10);

        return new JsonResponse(['data' => $data, 'meta' => $this->meta($f)]);
    }

    public function queries(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);
        $f = $this->dash->filters($request);

        return new JsonResponse(['data' => $this->dash->queries($f), 'meta' => $this->meta($f)]);
    }

    public function pages(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);
        $f = $this->dash->filters($request);

        return new JsonResponse(['data' => $this->dash->pages($f, $request->boolean('mapped_only')), 'meta' => $this->meta($f)]);
    }

    public function dimensions(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);
        $f = $this->dash->filters($request);
        $by = (string) $request->query('by', 'device');

        return new JsonResponse(['data' => $this->dash->dimension($f, $by), 'meta' => $this->meta($f) + ['by' => $by]]);
    }

    public function articles(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);
        $f = $this->dash->filters($request);
        $rows = collect($this->dash->pages(['limit' => 500] + $f, true))->keyBy('article_id');
        $list = $this->articles->list($f['site_id'], ['APPROVED'], 500);
        $snaps = $this->latestSnapshots($f['site_id'])->whereNotNull('article_id')->keyBy('article_id');

        return new JsonResponse(['data' => array_map(fn (array $a) => [
            'id' => $a['id'], 'title' => $a['title'], 'site_id' => $a['site_id'], 'primary_keyword' => $a['primary_keyword'],
            'performance' => $rows->get($a['id']),
            'classification' => ($s = $snaps->get($a['id'])) !== null ? ['value' => $s->classification, 'labels' => $s->labels ?? [], 'reasons' => $s->reasons ?? []] : null,
        ], $list), 'meta' => $this->meta($f)]);
    }

    public function article(Request $request, Workspace $workspace, string $articleRef): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);
        $a = preg_match('/^[0-9a-z]{26}$/i', $articleRef) === 1 ? $this->articles->find($articleRef) : null;
        if ($a === null) {
            throw ApiException::notFound();
        }
        $f = $this->dash->filters($request->merge(['site_id' => $a['site_id']]));
        $maps = UrlMapping::query()->where('site_id', $a['site_id'])->where('article_id', $a['id'])->orderByDesc('is_current')->get();
        $hashes = $maps->pluck('page_hash')->all();
        $series = SearchPageMetric::query()->where('site_id', $a['site_id'])->whereIn('page_hash', $hashes)->where('search_type', 'web')
            ->whereBetween('date', [$f['from'], $f['to']])->groupBy('date')->orderBy('date')
            ->selectRaw('date, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) as pw')->get()
            ->map(fn ($r) => ['date' => (string) $r->date, 'clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions,
                'ctr' => Metrics::ctr((int) $r->clicks, (int) $r->impressions), 'position' => (int) $r->impressions > 0 ? round((float) $r->getAttribute('pw') / (int) $r->impressions, 2) : null])->all();
        $period = SearchQueryPageMetric::query()->where('site_id', $a['site_id'])->max('period_end');
        $queries = $period === null ? [] : SearchQueryPageMetric::query()->where('site_id', $a['site_id'])->whereIn('page_hash', $hashes)->where('period_end', $period)
            ->orderByDesc('clicks')->orderByDesc('impressions')->limit(50)->get()
            ->map(fn ($r) => ['query' => $r->query, 'clicks' => $r->clicks, 'impressions' => $r->impressions, 'ctr' => Metrics::ctr($r->clicks, $r->impressions), 'position' => (float) $r->position])->all();
        $snap = ContentPerformanceSnapshot::query()->where('site_id', $a['site_id'])->where('article_id', $a['id'])->orderByDesc('period_end')->first();

        return new JsonResponse(['data' => [
            'article' => ['id' => $a['id'], 'title' => $a['title'], 'status' => $a['status'], 'site_id' => $a['site_id'], 'primary_keyword' => $a['primary_keyword']],
            'urls' => $maps->map(fn (UrlMapping $m) => ['url' => $m->page_url, 'source' => $m->source, 'is_current' => $m->is_current])->all(),
            'totals' => Metrics::aggregate($series), 'series' => $series, 'queries' => $queries, 'queries_period_end' => $period,
            'snapshot' => $snap === null ? null : AnalyticsPresenter::snapshot($snap),
            'opportunities' => SeoOpportunity::query()->where('article_id', $a['id'])->whereIn('status', ['open', 'accepted'])->limit(20)->get()->map(fn ($o) => AnalyticsPresenter::opportunity($o))->all(),
            'experiments' => SeoExperiment::query()->where('article_id', $a['id'])->orderByDesc('change_date')->limit(10)->get()->map(fn ($e) => AnalyticsPresenter::experiment($e))->all(),
            'refresh_jobs' => ContentRefreshJob::query()->where('article_id', $a['id'])->orderByDesc('created_at')->limit(10)->get()->map(fn ($j) => AnalyticsPresenter::refresh($j))->all(),
            'mapping_note' => $maps->isEmpty() ? __('analytics.labels.not_mapped') : null,
        ], 'meta' => $this->meta($f)]);
    }

    public function clusters(Request $request, Workspace $workspace, SiteDataset $dataset): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);
        $site = $request->query('site_id');
        if (! is_string($site) || $site === '') {
            return new JsonResponse(['data' => []]);
        }

        return new JsonResponse(['data' => $dataset->clusters($this->dash->site($site))]);
    }

    public function content(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);
        $site = $request->query('site_id');
        $siteId = is_string($site) && $site !== '' ? $this->dash->site($site) : null;
        $class = $request->query('classification');
        $snaps = $this->latestSnapshots($siteId);
        $counts = array_fill_keys(TrendClassifier::CLASSES, 0);
        foreach ($snaps as $s) {
            $counts[$s->classification] = ($counts[$s->classification] ?? 0) + 1;
        }
        $rows = is_string($class) && in_array($class, TrendClassifier::CLASSES, true) ? $snaps->where('classification', $class) : $snaps;

        return new JsonResponse(['data' => $rows->sortByDesc('clicks')->take(500)->values()->map(fn ($s) => AnalyticsPresenter::snapshot($s))->all(),
            'meta' => ['counts' => $counts, 'period_end' => $snaps->max('period_end'), 'classes' => TrendClassifier::CLASSES]]);
    }

    public function health(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('viewAny', AnalyticsProperty::class);
        $site = $request->query('site_id');
        $q = SeoHealthScore::query()->orderByDesc('date')->limit(60);
        if (is_string($site) && $site !== '') {
            $q->where('site_id', $this->dash->site($site));
        }
        $rows = $q->get();

        return new JsonResponse(['data' => ['latest' => ($l = $rows->first()) !== null ? AnalyticsPresenter::health($l) : null,
            'history' => $rows->map(fn ($h) => ['site_id' => $h->site_id, 'date' => $h->date, 'score' => $h->score])->values()->all(),
            'disclaimer' => __('analytics.labels.health_disclaimer')]]);
    }

    /**
     * @return Collection<int, ContentPerformanceSnapshot>
     */
    private function latestSnapshots(?string $siteId): Collection
    {
        $q = ContentPerformanceSnapshot::query()->when($siteId !== null, fn ($b) => $b->where('site_id', $siteId));
        $ends = (clone $q)->groupBy('site_id')->selectRaw('site_id, MAX(period_end) as pe')->pluck('pe', 'site_id');
        $out = collect();
        foreach ($ends as $sid => $pe) {
            $out = $out->merge(ContentPerformanceSnapshot::query()->where('site_id', (string) $sid)->where('period_end', (string) $pe)->limit(2000)->get());
        }

        return $out->values();
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private function meta(array $f): array
    {
        return ['filters' => array_intersect_key($f, array_flip(['site_id', 'from', 'to', 'prev_from', 'prev_to', 'search_type', 'device', 'country', 'q', 'sort', 'limit'])),
            'latest_date' => $f['latest'], 'source' => 'google_search_console', 'note' => __('analytics.labels.gsc_note')];
    }
}
