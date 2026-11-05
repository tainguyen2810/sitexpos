<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Crawler;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Security\SsrfViolation;
use SiteXPost\Modules\Research\Support\ResearchSettings;

/**
 * Polite, SSRF-safe fetcher for PUBLIC pages (customer site and competitors):
 * - every URL (and every redirect hop) goes through SsrfGuard and the
 *   connection is pinned to the vetted IP (no DNS rebinding);
 * - robots.txt is honoured (RobotsPolicy) before any page request;
 * - per-host rate limit + crawl delay, connect/total timeouts, size cap;
 * - only text/html and XML bodies are accepted; no cookies, no auth.
 */
class PublicFetcher
{
    public function __construct(
        private readonly SsrfGuard $guard,
        private readonly RobotsPolicy $robots,
        private readonly ResearchSettings $settings,
    ) {}

    /**
     * @throws FetchException
     */
    public function get(string $url, string $source = 'website_crawl', bool $checkRobots = true): FetchResult
    {
        if ($checkRobots && ! $this->robots->allows($url, $this, $source)) {
            throw new FetchException('robots_disallowed', 'Blocked by robots.txt');
        }

        $current = $url;
        for ($hop = 0; $hop <= 3; $hop++) {
            $response = $this->request($current, $source);
            $status = $response['status'];

            if ($status >= 300 && $status < 400 && $response['location'] !== null) {
                $next = self::resolve($current, $response['location']);
                if ($next === null || ! self::sameSite($url, $next)) {
                    throw new FetchException('redirect_off_site', 'Redirect to another site was not followed', $status);
                }
                if ($checkRobots && ! $this->robots->allows($next, $this, $source)) {
                    throw new FetchException('robots_disallowed', 'Blocked by robots.txt', $status);
                }
                $current = $next;

                continue;
            }

            return new FetchResult($current, $status, $response['type'], $response['body']);
        }

        throw new FetchException('too_many_redirects', 'Too many redirects');
    }

    /**
     * @return array{status: int, type: string, body: string, location: string|null}
     *
     * @throws FetchException
     */
    protected function request(string $url, string $source): array
    {
        try {
            $target = $this->guard->assertSafe($url);
        } catch (SsrfViolation $e) {
            throw new FetchException('blocked_url', 'URL not allowed: '.$e->getMessage());
        }

        $this->throttle($target['host'], $source);

        $options = ['allow_redirects' => false, 'http_errors' => false];
        if (filter_var($target['ip'], FILTER_VALIDATE_IP) !== false) {
            $options['curl'] = [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$target['ip']]];
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) $this->settings->global('user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.5',
                'Accept-Language' => 'vi,en;q=0.8',
            ])->withOptions($options)
                ->connectTimeout(5)
                ->timeout($this->settings->timeout($source))
                ->get($url);
        } catch (ConnectionException) {
            throw new FetchException('unreachable', 'Site unreachable or timed out');
        }

        $type = strtolower((string) $response->header('Content-Type'));
        $status = $response->status();
        $max = (int) $this->settings->global('max_page_bytes');
        $length = (int) $response->header('Content-Length');
        if ($length > $max) {
            throw new FetchException('too_large', 'Page larger than the allowed size', $status);
        }
        $body = $response->body();
        if (strlen($body) > $max) {
            $body = substr($body, 0, $max);
        }
        if ($status >= 200 && $status < 300 && $type !== '' && ! preg_match('#(text/html|xhtml|xml|text/plain)#', $type)) {
            throw new FetchException('unsupported_type', 'Unsupported content type', $status);
        }
        if ($status >= 400 && ! ($status >= 300 && $status < 400)) {
            throw new FetchException($status === 429 ? 'rate_limited' : 'http_'.$status, 'HTTP '.$status, $status);
        }

        return ['status' => $status, 'type' => $type, 'body' => $body, 'location' => $response->header('Location') ?: null];
    }

    /**
     * Per-host politeness: at most N requests/minute and a minimum delay
     * between requests (robots.txt Crawl-delay wins when larger).
     */
    protected function throttle(string $host, string $source): void
    {
        $key = 'research-fetch:'.$host;
        $perMinute = $this->settings->ratePerMinute($source);
        $waited = 0;
        while (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $wait = max(1, RateLimiter::availableIn($key));
            if ($waited + $wait > 30) {
                throw new FetchException('rate_limited_local', 'Per-host rate limit reached');
            }
            Sleep::for($wait)->seconds();
            $waited += $wait;
        }
        RateLimiter::hit($key, 60);

        $delayMs = max((int) $this->settings->global('crawl_delay_ms'), $this->robots->crawlDelayMs($host));
        $lastKey = 'research-fetch-last:'.$host;
        $last = (int) cache()->get($lastKey, 0);
        $now = (int) floor(microtime(true) * 1000);
        if ($last > 0 && $now - $last < $delayMs) {
            Sleep::for(min(10_000, $delayMs - ($now - $last)))->milliseconds();
        }
        cache()->put($lastKey, (int) floor(microtime(true) * 1000), 120);
    }

    public static function resolve(string $base, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || preg_match('#^(mailto|tel|javascript|data):#i', $href)) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return self::stripFragment($href);
        }
        $b = parse_url($base);
        if (! isset($b['scheme'], $b['host'])) {
            return null;
        }
        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($href, '//')) {
            return self::stripFragment($b['scheme'].':'.$href);
        }
        if (str_starts_with($href, '/')) {
            return self::stripFragment($origin.$href);
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');

        return self::stripFragment($origin.$dir.$href);
    }

    public static function sameSite(string $a, string $b): bool
    {
        $ha = preg_replace('/^www\./', '', strtolower((string) parse_url($a, PHP_URL_HOST)));
        $hb = preg_replace('/^www\./', '', strtolower((string) parse_url($b, PHP_URL_HOST)));

        return $ha !== '' && $ha === $hb;
    }

    private static function stripFragment(string $url): string
    {
        return (string) preg_replace('/#.*$/', '', $url);
    }
}
