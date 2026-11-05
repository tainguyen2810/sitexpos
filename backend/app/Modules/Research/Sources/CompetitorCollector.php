<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Sources;

use SiteXPost\Modules\Research\Crawler\FetchException;
use SiteXPost\Modules\Research\Crawler\HtmlExtractor;
use SiteXPost\Modules\Research\Crawler\PublicFetcher;
use SiteXPost\Modules\Research\Crawler\SitemapReader;
use SiteXPost\Modules\Research\Domain\CompetitorFinding;
use SiteXPost\Modules\Research\Domain\CompetitorWebsite;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Support\ResearchSettings;
use SiteXPost\Modules\Research\Support\SourceRecorder;
use SiteXPost\Modules\Research\Support\Text;

/**
 * Competitor research on PUBLIC pages only (robots.txt honoured, SSRF guard,
 * per-host rate limit, timeouts). Stores structure (titles, headings,
 * questions, categories) with URL and collection time — never page text.
 */
final class CompetitorCollector
{
    private const SKIP_PATH = '#/(wp-admin|wp-json|wp-login|cart|gio-hang|checkout|thanh-toan|my-account|tai-khoan|feed|tag|author|page/\d+|search|\?)#i';

    public function __construct(
        private readonly PublicFetcher $fetcher,
        private readonly HtmlExtractor $extractor,
        private readonly SitemapReader $sitemaps,
        private readonly SourceRecorder $recorder,
        private readonly ResearchSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(SeoCampaign $campaign, ResearchRun $run, int $maxCompetitors): array
    {
        if (! $this->settings->enabled('competitor_crawl') || ! (bool) $this->settings->global('competitor_crawl_enabled')) {
            return ['competitors' => 0, 'skipped' => 'disabled_by_admin', 'warnings' => []];
        }
        $competitors = CompetitorWebsite::query()->where('campaign_id', $campaign->id)->where('status', '!=', 'paused')
            ->orderBy('created_at')->limit(max(0, $maxCompetitors))->get();
        $maxPages = max(1, min(50, $campaign->settingsOrDefault()->max_competitor_pages));
        $warnings = [];
        $totalFindings = 0;

        foreach ($competitors as $competitor) {
            $origin = rtrim($competitor->url, '/');
            $source = $this->recorder->source($run, 'competitor_crawl', $competitor->name, ['url' => $origin, 'items_count' => 0]);
            $pages = 0;
            $findings = 0;
            $error = null;

            try {
                $home = $this->fetcher->get($origin.'/', 'competitor_crawl');
                $page = $this->extractor->extract($home->body, $home->url);
                $this->recorder->snapshot($source, $home->url, $home->status, $page);
                $pages++;
                $findings += $this->categoriesFrom($competitor, $run, $page['internal_links']);

                $map = $this->sitemaps->read($origin, 'competitor_crawl', (int) $this->settings->global('max_sitemap_urls'));
                $urls = array_values(array_filter(array_map(fn ($u) => $u['url'], $map['urls']), fn ($u) => preg_match(self::SKIP_PATH, (string) parse_url($u, PHP_URL_PATH)) !== 1));
                if ($urls === []) {
                    $urls = array_map(fn ($l) => $l['url'], $page['internal_links']);
                }
                // Prefer article-like URLs (slug with several words).
                usort($urls, fn ($a, $b) => substr_count((string) parse_url($b, PHP_URL_PATH), '-') <=> substr_count((string) parse_url($a, PHP_URL_PATH), '-'));

                $failed = 0;
                foreach (array_slice(array_unique($urls), 0, $maxPages - 1) as $url) {
                    if (! PublicFetcher::sameSite($origin, $url) || preg_match(self::SKIP_PATH, (string) parse_url($url, PHP_URL_PATH)) === 1) {
                        continue;
                    }
                    try {
                        $res = $this->fetcher->get($url, 'competitor_crawl');
                    } catch (FetchException $e) {
                        if (++$failed >= 5) {
                            break;
                        }

                        continue;
                    }
                    $p = $this->extractor->extract($res->body, $res->url);
                    $this->recorder->snapshot($source, $res->url, $res->status, $p);
                    $pages++;
                    if ($p['title'] !== '') {
                        $findings += $this->finding($competitor, $run, 'page', $p['title'], $res->url, array_slice(array_map(fn ($h) => $h['text'], array_filter($p['headings'], fn ($h) => $h['level'] <= 3)), 0, 20));
                    }
                    foreach (array_slice($p['questions'], 0, 10) as $q) {
                        $findings += $this->finding($competitor, $run, 'question', $q, $res->url, null);
                    }
                }
            } catch (FetchException $e) {
                $error = $e;
                $warnings[] = 'competitor_failed:'.$competitor->id.':'.$e->errorCode;
                $this->settings->recordFailure('competitor_crawl', $e->errorCode);
            }

            $source->forceFill([
                'status' => $error === null ? 'ok' : ($error->errorCode === 'robots_disallowed' ? 'blocked' : 'failed'),
                'items_count' => $findings, 'http_status' => $error?->httpStatus,
                'robots_allowed' => $error?->errorCode === 'robots_disallowed' ? false : ($error === null ? true : null),
                'error_code' => $error?->errorCode, 'error_message' => $error !== null ? mb_substr($error->getMessage(), 0, 500) : null,
            ])->save();
            $competitor->forceFill([
                'pages_crawled' => $pages, 'last_crawled_at' => now(),
                'robots_allowed' => $error?->errorCode === 'robots_disallowed' ? false : ($error === null ? true : $competitor->robots_allowed),
                'status' => $error?->errorCode === 'robots_disallowed' ? 'blocked' : ($error !== null ? 'failed' : 'active'),
                'last_error_code' => $error?->errorCode, 'last_error_message' => $error !== null ? mb_substr($error->getMessage(), 0, 500) : null,
            ])->save();
            $totalFindings += $findings;
        }

        return ['competitors' => $competitors->count(), 'findings' => $totalFindings, 'warnings' => $warnings];
    }

    /**
     * @param  list<array{url: string, anchor: string}>  $links
     */
    private function categoriesFrom(CompetitorWebsite $competitor, ResearchRun $run, array $links): int
    {
        $n = 0;
        foreach ($links as $link) {
            $path = (string) parse_url($link['url'], PHP_URL_PATH);
            if ($link['anchor'] !== '' && preg_match('#/(category|danh-muc|chuyen-muc|product-category|collections?)/#i', $path) === 1) {
                $n += $this->finding($competitor, $run, 'category', $link['anchor'], $link['url'], null);
            }
        }

        return $n;
    }

    /**
     * @param  list<string>|null  $headings
     */
    private function finding(CompetitorWebsite $competitor, ResearchRun $run, string $kind, string $title, ?string $url, ?array $headings): int
    {
        $title = Text::clean($title, 500);
        if (mb_strlen($title) < 4) {
            return 0;
        }
        $fp = Text::fingerprint($kind, Text::fold($title));
        $row = CompetitorFinding::query()->where(['competitor_website_id' => $competitor->id, 'fingerprint' => $fp])->first();
        $row ??= new CompetitorFinding;
        $row->forceFill([
            'competitor_website_id' => $competitor->id, 'research_run_id' => $run->id, 'kind' => $kind, 'title' => $title,
            'url' => $url !== null ? mb_substr($url, 0, 1000) : null, 'headings' => $headings, 'fingerprint' => $fp, 'fetched_at' => now(),
        ])->save();

        return 1;
    }
}
