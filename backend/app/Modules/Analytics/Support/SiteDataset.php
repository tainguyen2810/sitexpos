<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Support;

use Carbon\CarbonImmutable;
use SiteXPost\Modules\Analytics\Domain\ContentPerformanceSnapshot;
use SiteXPost\Modules\Analytics\Domain\SearchPageMetric;
use SiteXPost\Modules\Analytics\Domain\SearchQueryMetric;
use SiteXPost\Modules\Analytics\Domain\SearchQueryPageMetric;
use SiteXPost\Modules\Analytics\Domain\UrlMapping;
use SiteXPost\Modules\Analytics\Engine\Metrics;
use SiteXPost\Modules\Publishing\Contracts\PublicationDirectory;
use SiteXPost\Modules\Research\Contracts\PlannerDirectory;
use SiteXPost\Modules\Seo\Contracts\SeoGate;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * Collects everything the analysis layer needs for one site, from this
 * module's metric tables and other modules' read-only contracts. No
 * analysis happens here (data collection / analysis separation).
 */
final class SiteDataset
{
    public function __construct(
        private readonly ArticleCatalog $articles,
        private readonly PublicationDirectory $publications,
        private readonly SiteDirectory $sites,
        private readonly PlannerDirectory $planner,
        private readonly SeoGate $seo,
    ) {}

    /**
     * Page-level windows, mapped to articles.
     *
     * @return array<string, array<string, mixed>>
     */
    public function pages(string $siteId, CarbonImmutable $end, int $days): array
    {
        $windows = Windows::standard($end, $days);
        $agg = Windows::aggregate(SearchPageMetric::query()->where('site_id', $siteId)->where('search_type', 'web'), 'page_hash', $windows, ['page_url', 'article_id']);
        $mapped = UrlMapping::query()->where('site_id', $siteId)->whereNotNull('article_id')->pluck('article_id', 'page_hash')->all();
        foreach ($agg as $hash => &$p) {
            $p['article_id'] = $mapped[$hash] ?? $p['article_id'];
        }

        return $agg;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $siteId, CarbonImmutable $end, int $days): array
    {
        $site = $this->sites->find($siteId) ?? [];
        $host = UrlNormalizer::host((string) ($site['url'] ?? ''));
        $windows = Windows::standard($end, $days);
        $priorWindows = ['recent' => $windows['recent'], 'previous' => $windows['previous'], 'base1' => $windows['base1'], 'base2' => $windows['base2']];
        $queries = Windows::aggregate(SearchQueryMetric::query()->where('site_id', $siteId)->where('search_type', 'web'), 'query_hash', $priorWindows, ['query']);

        $periodEnd = SearchQueryPageMetric::query()->where('site_id', $siteId)->max('period_end');
        $qp = $periodEnd === null ? collect() : SearchQueryPageMetric::query()->where('site_id', $siteId)->where('period_end', $periodEnd)->where('search_type', 'web')
            ->orderByDesc('impressions')->limit(20000)->get(['query_hash', 'query', 'page_hash', 'page_url', 'clicks', 'impressions', 'position']);
        $mapped = UrlMapping::query()->where('site_id', $siteId)->whereNotNull('article_id')->pluck('article_id', 'page_hash')->all();
        $queryPages = $qp->map(fn ($r) => ['query_hash' => $r->query_hash, 'query' => $r->query, 'page_hash' => $r->page_hash, 'page_url' => $r->page_url,
            'clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions, 'position' => (float) $r->position, 'article_id' => $mapped[$r->page_hash] ?? null])->all();
        $topPage = [];
        foreach ($queryPages as $row) {
            if (! isset($topPage[$row['query_hash']]) || $row['impressions'] > $topPage[$row['query_hash']]['impressions']) {
                $topPage[$row['query_hash']] = ['page_url' => $row['page_url'], 'impressions' => $row['impressions'], 'article_id' => $row['article_id']];
            }
        }

        $snapshots = ContentPerformanceSnapshot::query()->where('site_id', $siteId)->where('period_end', $end->toDateString())->where('window_days', $days)->get();
        $pages = [];
        $byHash = [];
        $titles = [];
        foreach ($this->articles->list($siteId, ['APPROVED', 'NEEDS_HUMAN_REVIEW', 'QUALITY_REVIEW'], 1000) as $a) {
            $titles[$a['id']] = $a['title'];
        }
        $totalClicksPrev = 0;
        foreach ($snapshots as $s) {
            $row = ['page_hash' => $s->page_hash, 'page_url' => $s->page_url, 'article_id' => $s->article_id, 'title' => $s->article_id !== null ? ($titles[$s->article_id] ?? null) : null,
                'classification' => $s->classification, 'labels' => $s->labels ?? [], 'reasons' => $s->reasons ?? [],
                'recent' => ['clicks' => $s->clicks, 'impressions' => $s->impressions, 'position' => $s->position],
                'previous' => ['clicks' => $s->prev_clicks, 'impressions' => $s->prev_impressions, 'position' => $s->prev_position],
                'clicks_change' => Metrics::change($s->clicks, $s->prev_clicks), 'impressions_change' => Metrics::change($s->impressions, $s->prev_impressions)];
            $pages[] = $row;
            $byHash[$s->page_hash] = $row;
            $totalClicksPrev += $s->prev_clicks;
        }

        $qrows = [];
        $totalImpr = 0;
        foreach ($queries as $hash => $q) {
            $w = $q['windows'];
            $totalImpr += $w['recent']['impressions'];
            $qrows[] = ['query_hash' => $hash, 'query' => (string) $q['query'], 'recent' => $w['recent'], 'previous' => $w['previous'],
                'prior_impressions' => $w['base1']['impressions'] + $w['base2']['impressions'], 'top_page' => $topPage[$hash] ?? null];
        }

        return [
            'site_id' => $siteId, 'host' => $host, 'window_days' => $days, 'current_year' => (int) now()->format('Y'),
            'period' => ['recent' => $windows['recent'], 'previous' => $windows['previous']],
            'has_long_history' => SearchQueryMetric::query()->where('site_id', $siteId)->where('date', '<=', $windows['base2'][0])->exists(),
            'queries' => $qrows, 'query_pages' => $queryPages, 'pages' => $pages, 'pages_by_hash' => $byHash,
            'band_medians' => $this->bandMedians($pages), 'total_impressions' => $totalImpr, 'total_clicks_prev' => $totalClicksPrev,
            'managed' => $this->managed($siteId, $mapped),
            'inventory_urls' => $this->inventoryUrls($siteId), 'inventory_titles' => $this->inventoryTitles($siteId),
            'clusters' => $this->clusters($siteId),
        ];
    }

    /**
     * Site's own median CTR per position band (≥ 5 pages with enough data).
     *
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, float>
     */
    public function bandMedians(array $pages, int $minImpressions = 100): array
    {
        $bands = [];
        foreach ($pages as $p) {
            $r = $p['recent'];
            if ($r['impressions'] >= $minImpressions && ($ctr = Metrics::ctr($r['clicks'], $r['impressions'])) !== null) {
                $bands[Metrics::positionBand($r['position'])][] = $ctr;
            }
        }
        $out = [];
        foreach ($bands as $band => $values) {
            if (count($values) >= 5) {
                $out[$band] = round((float) Metrics::median($values), 2);
            }
        }

        return $out;
    }

    /**
     * Published SiteX articles with their approved content (text, links) and SEO metadata.
     *
     * @param  array<string, string>  $mapped  page_hash → article_id
     * @return list<array<string, mixed>>
     */
    public function managed(string $siteId, array $mapped): array
    {
        $hashByArticle = array_flip($mapped);
        $out = [];
        foreach ($this->articles->list($siteId, ['APPROVED'], 300) as $a) {
            $pub = $this->publications->current($a['id']);
            if ($pub === null || $pub['url'] === null) {
                continue;
            }
            $content = $this->articles->approvedContent($a['id']);
            $html = $content['html'] ?? '';
            preg_match_all('/<a\s[^>]*href="([^"]+)"/i', $html, $m);
            $links = [];
            foreach ($m[1] as $href) {
                $abs = str_starts_with($href, '/') ? rtrim((string) preg_replace('#^(https?://[^/]+).*$#', '$1', (string) $pub['url']), '/').$href : $href;
                if (preg_match('#^https?://#i', $abs) === 1) {
                    $links[] = UrlNormalizer::normalize($abs);
                }
            }
            try {
                $meta = $this->seo->metadata($a['id']);
            } catch (\Throwable) {
                $meta = null;
            }
            $updated = $pub['last_published_at'] ?? $a['updated_at'];
            $out[] = ['article_id' => $a['id'], 'title' => $a['title'], 'url' => $pub['url'], 'primary_keyword' => $a['primary_keyword'],
                'page_hash' => $hashByArticle[$a['id']] ?? UrlNormalizer::hash((string) $pub['url']),
                'text' => mb_strtolower(trim(html_entity_decode(strip_tags($html)))), 'links_norm' => array_values(array_unique($links)),
                'metadata' => $meta === null ? null : ['seo_title' => $meta['seo_title'] ?? null, 'meta_description' => $meta['meta_description'] ?? null],
                'days_since_update' => $updated !== null ? (int) CarbonImmutable::parse($updated)->diffInDays(now()) : null];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function inventoryUrls(string $siteId): array
    {
        $urls = [];
        foreach ($this->sites->contentIndex($siteId, ['post', 'page', 'product'], 20000) as $i) {
            if ($i['status'] === 'publish' && $i['url'] !== null) {
                $urls[] = UrlNormalizer::normalize($i['url']);
            }
        }

        return $urls;
    }

    /**
     * @return list<array{title: string, url: string|null}>
     */
    private function inventoryTitles(string $siteId): array
    {
        $out = [];
        foreach ($this->sites->contentIndex($siteId, ['post', 'page'], 20000) as $i) {
            if ($i['status'] === 'publish') {
                $out[] = ['title' => $i['title'], 'url' => $i['url']];
            }
        }

        return $out;
    }

    /**
     * @return list<array{name: string, ideas: int, published: int}>
     */
    public function clusters(string $siteId): array
    {
        $ideas = $this->planner->ideasForSite($siteId, 2000);
        $published = [];
        foreach ($this->articles->list($siteId, ['APPROVED'], 1000) as $a) {
            if ($a['content_idea_id'] !== null && ($this->publications->current($a['id'])['url'] ?? null) !== null) {
                $published[$a['content_idea_id']] = true;
            }
        }
        $groups = [];
        foreach ($ideas as $i) {
            $name = (string) ($i['cluster'] ?? '');
            if ($name === '') {
                continue;
            }
            $groups[$name] ??= ['name' => $name, 'ideas' => 0, 'published' => 0];
            $groups[$name]['ideas']++;
            if (isset($published[$i['id']])) {
                $groups[$name]['published']++;
            }
        }

        return array_values($groups);
    }
}
