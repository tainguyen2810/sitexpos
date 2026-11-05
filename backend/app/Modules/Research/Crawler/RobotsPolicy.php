<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Crawler;

use Illuminate\Support\Facades\Cache;

/**
 * robots.txt compliance (RFC 9309):
 * - 2xx: rules of the most specific group ("sitexpostbot", else "*");
 *   longest matching rule wins, Allow wins a tie; `*` and `$` supported;
 * - 4xx: no restrictions;
 * - 5xx / unreachable: everything disallowed (assume complete disallow).
 * Parsed result cached per host for 6 hours.
 */
class RobotsPolicy
{
    public const AGENT = 'sitexpostbot';

    private const TTL = 21600;

    /** @var array<string, array{rules: list<array{0: bool, 1: string}>, delay_ms: int, sitemaps: list<string>, disallow_all: bool}> */
    private array $memo = [];

    /**
     * @throws FetchException when robots.txt cannot be reached at all
     */
    public function allows(string $url, PublicFetcher $fetcher, string $source): bool
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $policy = $this->policy($parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''), $fetcher, $source);
        if ($policy['disallow_all']) {
            return false;
        }
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return self::evaluate($policy['rules'], $path);
    }

    /**
     * @return list<string>
     */
    public function sitemaps(string $origin, PublicFetcher $fetcher, string $source): array
    {
        return $this->policy($origin, $fetcher, $source)['sitemaps'];
    }

    public function crawlDelayMs(string $host): int
    {
        foreach ($this->memo as $origin => $policy) {
            if (parse_url($origin, PHP_URL_HOST) === $host) {
                return $policy['delay_ms'];
            }
        }

        return 0;
    }

    /**
     * @return array{rules: list<array{0: bool, 1: string}>, delay_ms: int, sitemaps: list<string>, disallow_all: bool}
     */
    private function policy(string $origin, PublicFetcher $fetcher, string $source): array
    {
        if (isset($this->memo[$origin])) {
            return $this->memo[$origin];
        }

        /** @var array{rules: list<array{0: bool, 1: string}>, delay_ms: int, sitemaps: list<string>, disallow_all: bool} $policy */
        $policy = Cache::remember('research-robots:'.sha1($origin), self::TTL, function () use ($origin, $fetcher, $source): array {
            try {
                $result = $fetcher->get($origin.'/robots.txt', $source, false);

                return self::parse($result->body);
            } catch (FetchException $e) {
                // Host down / timeout: report the site as unreachable (not as
                // "blocked by robots") and do not cache the outcome.
                if ($e->httpStatus === null && in_array($e->errorCode, ['unreachable', 'blocked_url', 'rate_limited_local'], true)) {
                    throw $e;
                }
                if ($e->httpStatus !== null && $e->httpStatus >= 400 && $e->httpStatus < 500 && $e->httpStatus !== 429) {
                    return ['rules' => [], 'delay_ms' => 0, 'sitemaps' => [], 'disallow_all' => false];
                }

                return ['rules' => [], 'delay_ms' => 0, 'sitemaps' => [], 'disallow_all' => true];
            }
        });

        return $this->memo[$origin] = $policy;
    }

    /**
     * @return array{rules: list<array{0: bool, 1: string}>, delay_ms: int, sitemaps: list<string>, disallow_all: bool}
     */
    public static function parse(string $body): array
    {
        $groups = [];
        $agents = [];
        $inRules = false;
        $sitemaps = [];

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                if (preg_match('#^https?://#i', $value)) {
                    $sitemaps[] = $value;
                }

                continue;
            }
            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($value);
                foreach ($agents as $a) {
                    $groups[$a] ??= ['rules' => [], 'delay' => 0];
                }

                continue;
            }
            if (in_array($field, ['allow', 'disallow', 'crawl-delay'], true)) {
                $inRules = true;
                foreach ($agents as $a) {
                    if ($field === 'crawl-delay') {
                        $groups[$a]['delay'] = (int) round(min(30.0, max(0.0, (float) $value)) * 1000);
                    } elseif ($value !== '') {
                        $groups[$a]['rules'][] = [$field === 'allow', $value];
                    }
                }
            }
        }

        $group = null;
        foreach ($groups as $agent => $g) {
            if ($agent !== '*' && str_contains(self::AGENT, $agent)) {
                $group = $g;
                break;
            }
        }
        $group ??= $groups['*'] ?? ['rules' => [], 'delay' => 0];

        return ['rules' => $group['rules'], 'delay_ms' => $group['delay'], 'sitemaps' => array_values(array_unique($sitemaps)), 'disallow_all' => false];
    }

    /**
     * @param  list<array{0: bool, 1: string}>  $rules
     */
    public static function evaluate(array $rules, string $path): bool
    {
        $best = -1;
        $allowed = true;
        foreach ($rules as [$allow, $pattern]) {
            $regex = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')).'#';
            if (preg_match($regex, $path) === 1) {
                $len = strlen($pattern);
                if ($len > $best || ($len === $best && $allow)) {
                    $best = $len;
                    $allowed = $allow;
                }
            }
        }

        return $allowed;
    }
}
