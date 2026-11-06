<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Crawler;

/**
 * Discovers sitemap URLs (robots.txt Sitemap: lines, then the WordPress /
 * common defaults) and reads <urlset>/<sitemapindex> safely: no network
 * entity loading, same-site URLs only, bounded counts.
 */
final class SitemapReader
{
    public function __construct(private readonly PublicFetcher $fetcher, private readonly RobotsPolicy $robots) {}

    /**
     * @return array{sitemap: string|null, urls: list<array{url: string, lastmod: string|null}>}
     */
    public function read(string $origin, string $source, int $maxUrls = 500): array
    {
        $origin = rtrim($origin, '/');
        $candidates = array_merge(
            $this->robots->sitemaps($origin, $this->fetcher, $source),
            [$origin.'/wp-sitemap.xml', $origin.'/sitemap_index.xml', $origin.'/sitemap.xml'],
        );

        foreach (array_unique($candidates) as $sitemap) {
            if (! PublicFetcher::sameSite($origin, $sitemap)) {
                continue;
            }
            $urls = [];
            $this->collect($sitemap, $origin, $source, $maxUrls, $urls, 0);
            if ($urls !== []) {
                return ['sitemap' => $sitemap, 'urls' => array_values($urls)];
            }
        }

        return ['sitemap' => null, 'urls' => []];
    }

    /**
     * @param  array<string, array{url: string, lastmod: string|null}>  $urls
     */
    private function collect(string $sitemap, string $origin, string $source, int $max, array &$urls, int $depth): void
    {
        if ($depth > 2 || count($urls) >= $max) {
            return;
        }
        try {
            $body = $this->fetcher->get($sitemap, $source)->body;
        } catch (FetchException) {
            return;
        }

        $xml = self::parse($body);
        if ($xml === null) {
            return;
        }

        $children = 0;
        foreach ($xml['sitemaps'] as $child) {
            if (++$children > 5) {
                break;
            }
            if (PublicFetcher::sameSite($origin, $child)) {
                $this->collect($child, $origin, $source, $max, $urls, $depth + 1);
            }
        }
        foreach ($xml['urls'] as $entry) {
            if (count($urls) >= $max) {
                break;
            }
            if (PublicFetcher::sameSite($origin, $entry['url'])) {
                $urls[$entry['url']] = $entry;
            }
        }
    }

    /**
     * @return array{sitemaps: list<string>, urls: list<array{url: string, lastmod: string|null}>}|null
     */
    public static function parse(string $body): ?array
    {
        if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            return null; // no DTD/entities in sitemaps (XXE / billion laughs)
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            return null;
        }

        $out = ['sitemaps' => [], 'urls' => []];
        foreach ($xml->children() as $node) {
            $loc = trim((string) $node->loc);
            if ($loc === '' || ! preg_match('#^https?://#i', $loc)) {
                continue;
            }
            if ($node->getName() === 'sitemap') {
                $out['sitemaps'][] = $loc;
            } elseif ($node->getName() === 'url') {
                $lastmod = trim((string) $node->lastmod);
                $out['urls'][] = ['url' => mb_substr($loc, 0, 1000), 'lastmod' => $lastmod !== '' ? mb_substr($lastmod, 0, 40) : null];
            }
        }

        return $out;
    }
}
