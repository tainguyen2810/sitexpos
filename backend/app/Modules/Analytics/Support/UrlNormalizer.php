<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Support;

/**
 * Canonical form of a page URL for matching Search Console pages with
 * WordPress posts: lower-case scheme/host, no fragment, no tracking
 * parameters, no trailing slash difference. Matching is EXACT on this
 * form — never "similar" URLs — so two different posts can never merge.
 */
final class UrlNormalizer
{
    private const TRACKING = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', '_ga'];

    public static function normalize(string $url): string
    {
        $url = trim($url);
        $p = parse_url($url);
        if ($p === false || ! isset($p['host'])) {
            return mb_strtolower($url);
        }
        $scheme = strtolower($p['scheme'] ?? 'https');
        $host = strtolower($p['host']);
        $port = isset($p['port']) && ! in_array((int) $p['port'], [80, 443], true) ? ':'.$p['port'] : '';
        $path = $p['path'] ?? '/';
        $path = $path === '' ? '/' : rawurldecode($path);
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        $query = '';
        if (isset($p['query']) && $p['query'] !== '') {
            parse_str($p['query'], $params);
            $params = array_diff_key($params, array_flip(self::TRACKING));
            ksort($params);
            $query = $params === [] ? '' : '?'.http_build_query($params);
        }

        return $scheme.'://'.$host.$port.$path.$query;
    }

    public static function hash(string $url): string
    {
        return sha1(self::normalize($url));
    }

    public static function queryHash(string $query): string
    {
        return sha1(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $query) ?? $query)));
    }

    public static function host(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
    }
}
