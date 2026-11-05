<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Sources;

use SiteXPost\Modules\Research\Crawler\FetchException;
use SiteXPost\Modules\Research\Crawler\HtmlExtractor;
use SiteXPost\Modules\Research\Crawler\PublicFetcher;
use SiteXPost\Modules\Research\Crawler\SitemapReader;
use SiteXPost\Modules\Research\Domain\ResearchRun;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\WebsiteKnowledge;
use SiteXPost\Modules\Research\Domain\WebsiteProfile;
use SiteXPost\Modules\Research\Support\ResearchSettings;
use SiteXPost\Modules\Research\Support\SourceRecorder;
use SiteXPost\Modules\Research\Support\Text;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

/**
 * Website Intelligence data collection:
 * 1. the WordPress index synced by the connector (posts, pages, products,
 *    categories, tags) — available even when the site is offline right now;
 * 2. public pages of the site (homepage, sitemap, recent posts): title, meta,
 *    headings, questions, internal links.
 * Failures never abort the run: they become warnings + failed source rows.
 */
final class WebsiteCollector
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly PublicFetcher $fetcher,
        private readonly HtmlExtractor $extractor,
        private readonly SitemapReader $sitemaps,
        private readonly SourceRecorder $recorder,
        private readonly ResearchSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(SeoCampaign $campaign, ResearchRun $run): array
    {
        $warnings = [];
        $site = $this->sites->find($campaign->site_id);
        if ($site === null) {
            return ['warnings' => ['site_missing'], 'pages' => 0];
        }

        $profile = WebsiteProfile::query()->where('site_id', $site['id'])->first() ?? new WebsiteProfile;
        if (! $profile->exists) {
            $profile->forceFill(['site_id' => $site['id'], 'status' => 'pending', 'language' => $site['language']])->save();
        }

        // 1) WordPress index ---------------------------------------------------
        $content = $this->sites->content($site['id']);
        $terms = $this->sites->taxonomies($site['id']);
        $counts = ['post' => 0, 'page' => 0, 'product' => 0];
        foreach ($content as $item) {
            $counts[$item['post_type']] = ($counts[$item['post_type']] ?? 0) + 1;
        }
        $termCounts = [];
        foreach ($terms as $t) {
            $termCounts[$t['taxonomy']] = ($termCounts[$t['taxonomy']] ?? 0) + 1;
        }
        $this->recorder->source($run, 'wordpress', 'WordPress index', [
            'url' => $site['url'],
            'status' => $content === [] && $terms === [] ? 'skipped' : 'ok',
            'items_count' => count($content) + count($terms),
            'error_code' => $content === [] && $terms === [] ? 'not_synced' : null,
            'error_message' => $content === [] && $terms === [] ? 'Chưa có dữ liệu đồng bộ từ WordPress' : null,
        ]);
        if ($content === [] && $terms === []) {
            $warnings[] = 'wordpress_not_synced';
        }

        // 2) Public crawl -------------------------------------------------------
        $pages = 0;
        $internalLinks = 0;
        $sitemapInfo = ['sitemap' => null, 'urls' => []];
        $home = null;
        if ($this->settings->enabled('website_crawl') && (bool) $this->settings->global('site_crawl_enabled')) {
            $origin = rtrim($site['url'], '/');
            try {
                $res = $this->fetcher->get($origin.'/', 'website_crawl');
                $home = $this->extractor->extract($res->body, $res->url);
                $src = $this->recorder->source($run, 'website_crawl', 'Trang chủ', ['url' => $res->url, 'http_status' => $res->status, 'robots_allowed' => true, 'items_count' => 1]);
                $this->recorder->snapshot($src, $res->url, $res->status, $home);
                $pages++;
                $internalLinks += count($home['internal_links']);
            } catch (FetchException $e) {
                $warnings[] = 'site_unreachable:'.$e->errorCode;
                $this->recorder->source($run, 'website_crawl', 'Trang chủ', [
                    'url' => $origin, 'status' => $e->errorCode === 'robots_disallowed' ? 'blocked' : 'failed',
                    'http_status' => $e->httpStatus, 'robots_allowed' => $e->errorCode === 'robots_disallowed' ? false : null,
                    'error_code' => $e->errorCode, 'error_message' => mb_substr($e->getMessage(), 0, 500),
                ]);
                $this->settings->recordFailure('website_crawl', $e->errorCode);
            }

            if ($home !== null) {
                $sitemapInfo = $this->sitemaps->read($origin, 'website_crawl', (int) $this->settings->global('max_sitemap_urls'));
                $this->recorder->source($run, 'website_crawl', 'Sitemap', [
                    'url' => $sitemapInfo['sitemap'], 'status' => $sitemapInfo['sitemap'] === null ? 'skipped' : 'ok',
                    'items_count' => count($sitemapInfo['urls']), 'error_code' => $sitemapInfo['sitemap'] === null ? 'no_sitemap' : null,
                ]);

                // Recent posts first (structure: headings, questions, links).
                $max = max(0, min(100, $campaign->settingsOrDefault()->max_site_pages) - 1);
                $urls = array_values(array_filter(array_map(fn ($c) => $c['url'], array_filter($content, fn ($c) => $c['post_type'] === 'post'))));
                if ($urls === []) {
                    $urls = array_map(fn ($u) => $u['url'], $sitemapInfo['urls']);
                }
                $pageSource = $this->recorder->source($run, 'website_crawl', 'Bài viết công khai', ['url' => $origin, 'items_count' => 0]);
                $failed = 0;
                foreach (array_slice(array_unique($urls), 0, $max) as $url) {
                    if (! PublicFetcher::sameSite($origin, $url)) {
                        continue;
                    }
                    try {
                        $res = $this->fetcher->get($url, 'website_crawl');
                        $page = $this->extractor->extract($res->body, $res->url);
                        $this->recorder->snapshot($pageSource, $res->url, $res->status, $page);
                        $pages++;
                        $internalLinks += count($page['internal_links']);
                    } catch (FetchException $e) {
                        if (++$failed >= 5) {
                            $warnings[] = 'site_pages_failed:'.$e->errorCode;
                            break;
                        }
                    }
                }
                $pageSource->forceFill(['items_count' => $pages - 1, 'status' => $pages > 1 || $max === 0 ? 'ok' : ($failed > 0 ? 'failed' : 'skipped')])->save();
            }
        } else {
            $this->recorder->source($run, 'website_crawl', 'Trang chủ', ['status' => 'skipped', 'error_code' => 'disabled_by_admin']);
        }

        // 3) Profile + deterministic knowledge (source = website) ---------------
        $categories = array_values(array_filter($terms, fn ($t) => in_array($t['taxonomy'], ['category', 'product_cat'], true) && $t['count'] > 0));
        $profile->forceFill([
            'title' => $home['site_name'] ?? ($home['title'] ?? null) ?? $site['name'],
            'tagline' => $home['title'] ?? null,
            'description' => $home['meta_description'] ?? null,
            'language' => $home['language'] ?? $profile->language ?? $site['language'],
            'region' => $campaign->market,
            'sitemap_url' => $sitemapInfo['sitemap'],
            'sitemap_url_count' => count($sitemapInfo['urls']),
            'stats' => [
                'posts' => $counts['post'], 'pages' => $counts['page'], 'products' => $counts['product'],
                'categories' => ($termCounts['category'] ?? 0) + ($termCounts['product_cat'] ?? 0),
                'tags' => ($termCounts['post_tag'] ?? 0) + ($termCounts['product_tag'] ?? 0),
                'crawled_pages' => $pages, 'internal_links' => $internalLinks,
                'woocommerce' => $site['woocommerce'],
            ],
            'covered_topics' => array_slice(array_map(fn ($t) => ['name' => $t['name'], 'count' => $t['count'], 'taxonomy' => $t['taxonomy']], $categories), 0, 40),
            'status' => 'ready',
            'last_error_code' => $home === null ? 'site_unreachable' : null,
            'last_crawled_at' => $home !== null ? now() : $profile->last_crawled_at,
        ])->save();

        $this->knowledge($profile, 'brand', (string) ($home['site_name'] ?? $site['name']), 60, [['url' => $site['url']]]);
        if (! empty($home['meta_description'])) {
            $this->knowledge($profile, 'positioning', (string) $home['meta_description'], 50, [['url' => $site['url'], 'field' => 'meta_description']]);
        }
        foreach (array_slice($categories, 0, 8) as $cat) {
            $this->knowledge($profile, 'covered_topic', $cat['name'], 70, [['taxonomy' => $cat['taxonomy'], 'posts' => $cat['count']]]);
        }
        foreach (array_slice(array_filter($content, fn ($c) => $c['post_type'] === 'product'), 0, 10) as $product) {
            $this->knowledge($profile, 'product_line', $product['title'], 70, [['url' => $product['url']]]);
        }

        return [
            'warnings' => $warnings,
            'wordpress_items' => count($content),
            'taxonomies' => count($terms),
            'pages' => $pages,
            'sitemap_urls' => count($sitemapInfo['urls']),
            'site_offline' => $home === null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     */
    public function knowledge(WebsiteProfile $profile, string $kind, string $value, int $confidence, array $evidence, string $source = 'website'): ?WebsiteKnowledge
    {
        $value = Text::clean($value, 1000);
        if ($value === '') {
            return null;
        }
        $fp = Text::fingerprint($kind, Text::fold($value));
        $row = WebsiteKnowledge::query()->where(['website_profile_id' => $profile->id, 'fingerprint' => $fp])->first();
        if ($row !== null) {
            // never override a user's edit/confirmation/rejection
            return $row;
        }
        $row = new WebsiteKnowledge;
        $row->forceFill([
            'website_profile_id' => $profile->id, 'kind' => $kind, 'value' => $value, 'source' => $source,
            'confidence' => max(0, min(100, $confidence)), 'evidence' => $evidence, 'status' => 'suggested', 'fingerprint' => $fp,
        ])->save();

        return $row;
    }
}
