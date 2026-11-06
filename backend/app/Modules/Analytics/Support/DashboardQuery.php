<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\SearchPageMetric;
use SiteXPost\Modules\Analytics\Domain\SearchPerformanceDaily;
use SiteXPost\Modules\Analytics\Domain\SearchQueryMetric;
use SiteXPost\Modules\Analytics\Engine\Metrics;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

/**
 * Read model behind the Analytics dashboard. Every filter is validated
 * against a whitelist; the SQL fragments are constants (no user input in
 * raw expressions). Rows are tenant-scoped by the models' global scope.
 */
final class DashboardQuery
{
    public const SORTS = ['clicks', 'impressions', 'ctr', 'position', 'clicks_change'];

    private const SUMS = 'SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) as pos_weight';

    public function __construct(private readonly SiteDirectory $sites) {}

    /**
     * @return array{site_id: ?string, from: string, to: string, prev_from: string, prev_to: string, search_type: string, device: ?string, country: ?string, q: ?string, sort: string, limit: int, latest: ?string}
     */
    public function filters(Request $request): array
    {
        $siteId = $request->query('site_id');
        $siteId = is_string($siteId) && $siteId !== '' ? $this->site($siteId) : null;
        $latest = AnalyticsProperty::query()->where('provider', 'gsc')->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))->max('last_complete_date')
            ?? SearchPerformanceDaily::query()->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))->max('date');
        $end = $this->date($request->query('to')) ?? ($latest !== null ? CarbonImmutable::parse((string) $latest) : CarbonImmutable::now('UTC')->subDays(3));
        $start = $this->date($request->query('from')) ?? $end->subDays(27);
        if ($start->gt($end) || $start->diffInDays($end) > 500) {
            throw ValidationException::withMessages(['from' => [__('analytics.errors.invalid_range')]]);
        }
        $days = (int) $start->diffInDays($end) + 1;
        $type = (string) $request->query('search_type', 'web');
        $device = $request->query('device');
        $country = $request->query('country');
        $q = $request->query('q');
        $sort = (string) $request->query('sort', 'clicks');

        return [
            'site_id' => $siteId, 'from' => $start->toDateString(), 'to' => $end->toDateString(),
            'prev_from' => $start->subDays($days)->toDateString(), 'prev_to' => $start->subDay()->toDateString(),
            'search_type' => in_array($type, ['web', 'image', 'video', 'news', 'discover', 'googleNews'], true) ? $type : 'web',
            'device' => is_string($device) && in_array($device, ['desktop', 'mobile', 'tablet'], true) ? $device : null,
            'country' => is_string($country) && preg_match('/^[a-z]{3}$/i', $country) === 1 ? strtolower($country) : null,
            'q' => is_string($q) && trim($q) !== '' ? mb_substr(trim($q), 0, 100) : null,
            'sort' => in_array($sort, self::SORTS, true) ? $sort : 'clicks',
            'limit' => max(1, min(500, (int) $request->query('limit', 100))),
            'latest' => $latest !== null ? (string) $latest : null,
        ];
    }

    /** A site id from the URL / query string, resolved inside the tenant (another tenant's id → 404). */
    public function site(string $siteId): string
    {
        if (preg_match('/^[0-9a-z]{26}$/i', $siteId) !== 1 || $this->sites->find($siteId) === null) {
            throw ApiException::notFound();
        }

        return $siteId;
    }

    /**
     * Totals + daily series for the period and the comparison period.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public function overview(array $f): array
    {
        $cur = $this->totals($this->daily($f), $f['from'], $f['to']);
        $prev = $this->totals($this->daily($f), $f['prev_from'], $f['prev_to']);
        $series = $this->daily($f)->whereBetween('date', [$f['from'], $f['to']])->groupBy('date')->orderBy('date')
            ->selectRaw('date, '.self::SUMS)->get()
            ->map(fn ($r) => ['date' => (string) $r->date] + $this->shape($r))->all();

        return [
            'period' => ['from' => $f['from'], 'to' => $f['to']], 'previous_period' => ['from' => $f['prev_from'], 'to' => $f['prev_to']],
            'totals' => $cur, 'previous' => $prev,
            'change' => ['clicks' => Metrics::change($cur['clicks'], $prev['clicks']), 'impressions' => Metrics::change($cur['impressions'], $prev['impressions'])],
            'series' => $series, 'latest_date' => $f['latest'],
            'has_data' => $cur['impressions'] > 0 || $prev['impressions'] > 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    public function dimension(array $f, string $dimension): array
    {
        $col = match ($dimension) {
            'device' => 'device', 'country' => 'country', default => throw ApiException::notFound(),
        };

        return $this->daily($f)->whereBetween('date', [$f['from'], $f['to']])->groupBy($col)->selectRaw($col.' as dim, '.self::SUMS)
            ->orderByDesc('clicks')->limit(100)->get()->map(fn ($r) => ['value' => (string) $r->dim] + $this->shape($r))->all();
    }

    /**
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    public function queries(array $f): array
    {
        return $this->ranked(SearchQueryMetric::query(), 'query_hash', 'query', $f);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    public function pages(array $f, bool $mappedOnly = false): array
    {
        $q = SearchPageMetric::query();
        if ($mappedOnly) {
            $q->whereNotNull('article_id');
        }

        return $this->ranked($q, 'page_hash', 'page_url', $f, true);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $base
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    private function ranked(Builder $base, string $hash, string $label, array $f, bool $withArticle = false): array
    {
        $scoped = fn () => (clone $base)->where('search_type', $f['search_type'])
            ->when($f['site_id'] !== null, fn ($q) => $q->where('site_id', $f['site_id']))
            ->when($f['q'] !== null, fn ($q) => $q->where($label, 'like', '%'.addcslashes((string) $f['q'], '%_\\').'%'));
        $select = $hash.', MAX('.$label.') as label'.($withArticle ? ', MAX(article_id) as article_id' : '').', '.self::SUMS;
        $order = match ($f['sort']) {
            'impressions' => 'impressions', 'position' => 'pos_weight', default => 'clicks',
        };
        $rows = $scoped()->whereBetween('date', [$f['from'], $f['to']])->groupBy($hash)->selectRaw($select)
            ->orderByDesc($order)->limit(min(2000, $f['limit'] * 4))->get();
        $prev = $scoped()->whereBetween('date', [$f['prev_from'], $f['prev_to']])->whereIn($hash, $rows->pluck($hash))->groupBy($hash)
            ->selectRaw($hash.', '.self::SUMS)->get()->keyBy($hash);

        $out = $rows->map(function ($r) use ($prev, $hash, $withArticle) {
            $p = $prev->get($r->{$hash});
            $cur = $this->shape($r);
            $pv = $p !== null ? $this->shape($p) : ['clicks' => 0, 'impressions' => 0, 'ctr' => null, 'position' => null];

            return ['key' => (string) $r->{$hash}, 'label' => (string) $r->label] + ($withArticle ? ['article_id' => $r->article_id] : []) + $cur + [
                'previous' => $pv, 'clicks_change' => Metrics::change($cur['clicks'], $pv['clicks']),
                'position_change' => $cur['position'] !== null && $pv['position'] !== null ? round($pv['position'] - $cur['position'], 2) : null,
            ];
        })->all();
        usort($out, fn ($a, $b) => match ($f['sort']) {
            'ctr' => ($b['ctr'] ?? -1) <=> ($a['ctr'] ?? -1),
            'position' => ($a['position'] ?? 999) <=> ($b['position'] ?? 999),
            'clicks_change' => ($a['clicks_change'] ?? PHP_INT_MAX) <=> ($b['clicks_change'] ?? PHP_INT_MAX),
            'impressions' => $b['impressions'] <=> $a['impressions'],
            default => $b['clicks'] <=> $a['clicks'],
        });

        return array_slice($out, 0, $f['limit']);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return Builder<SearchPerformanceDaily>
     */
    private function daily(array $f): Builder
    {
        return SearchPerformanceDaily::query()->where('search_type', $f['search_type'])
            ->when($f['site_id'] !== null, fn ($q) => $q->where('site_id', $f['site_id']))
            ->when($f['device'] !== null, fn ($q) => $q->where('device', $f['device']))
            ->when($f['country'] !== null, fn ($q) => $q->where('country', $f['country']));
    }

    /**
     * @param  Builder<SearchPerformanceDaily>  $q
     * @return array{clicks: int, impressions: int, ctr: float|null, position: float|null}
     */
    private function totals(Builder $q, string $from, string $to): array
    {
        $r = $q->whereBetween('date', [$from, $to])->selectRaw(self::SUMS)->first();

        return $r === null ? ['clicks' => 0, 'impressions' => 0, 'ctr' => null, 'position' => null] : $this->shape($r);
    }

    /**
     * @return array{clicks: int, impressions: int, ctr: float|null, position: float|null}
     */
    private function shape(object $r): array
    {
        $clicks = (int) ($r->clicks ?? 0);
        $impr = (int) ($r->impressions ?? 0);

        return ['clicks' => $clicks, 'impressions' => $impr, 'ctr' => Metrics::ctr($clicks, $impr), 'position' => $impr > 0 ? round((float) $r->pos_weight / $impr, 2) : null];
    }

    private function date(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
            return null;
        }
        $d = CarbonImmutable::createFromFormat('Y-m-d', $v, 'UTC');

        return $d === null ? null : $d->startOfDay();
    }
}
