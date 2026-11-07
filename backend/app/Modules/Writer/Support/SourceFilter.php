<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Support;

use SiteXPost\Modules\Writer\Domain\SourceRule;

/**
 * Source filter (FACT-CHECK-POLICY.md §7): the workspace's own prefer /
 * block domains plus the Super Admin platform block list. A domain matches
 * itself and every subdomain ("example.com" covers "docs.example.com").
 * Block always wins over prefer. Rules only — no model opinion.
 */
final class SourceFilter
{
    /** Reliability points added to a preferred source (capped at 100). */
    public const PREFER_BONUS = 15;

    /** @var array<string, array{prefer: list<string>, block: list<string>}> */
    private array $cache = [];

    public function __construct(private readonly WriterSettings $settings) {}

    /**
     * "https://www.Example.com/a" / "*.example.com" / "example.com." → "example.com"; invalid → null.
     */
    public static function normalizeDomain(string $input): ?string
    {
        $v = strtolower(trim($input));
        if ($v === '') {
            return null;
        }
        if (str_contains($v, '://')) {
            $v = (string) parse_url($v, PHP_URL_HOST);
        } else {
            $v = explode('/', $v, 2)[0];
        }
        $v = rtrim(preg_replace('/^(\*\.|www\.)/', '', $v) ?? '', '.');
        $v = (string) preg_replace('/:\d+$/', '', $v);
        if (strlen($v) > 253 || preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $v) !== 1) {
            return null;
        }

        return $v;
    }

    public static function host(string $url): string
    {
        return rtrim(strtolower((string) parse_url(trim($url), PHP_URL_HOST)), '.');
    }

    /**
     * @param  list<string>  $domains
     */
    public static function matches(string $host, array $domains): bool
    {
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        foreach ($domains as $d) {
            if ($host === $d || str_ends_with($host, '.'.$d)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registrable domain used to count INDEPENDENT sources
     * ("docs.example.com.vn" → "example.com.vn").
     */
    public static function site(string $url): string
    {
        $parts = explode('.', preg_replace('/^www\./', '', self::host($url)) ?? '');
        $n = count($parts);
        if ($n >= 3 && in_array($parts[$n - 2], ['com', 'co', 'net', 'org', 'gov', 'edu', 'ac', 'info', 'biz', 'name', 'pro', 'health'], true) && strlen($parts[$n - 1]) === 2) {
            return implode('.', array_slice($parts, -3));
        }

        return implode('.', array_slice($parts, -2));
    }

    /**
     * @return array{prefer: list<string>, block: list<string>}
     */
    public function lists(string $workspaceId): array
    {
        if (isset($this->cache[$workspaceId])) {
            return $this->cache[$workspaceId];
        }
        $rules = SourceRule::query()->where('workspace_id', $workspaceId)->get(['domain', 'mode']);
        $global = [];
        foreach ((array) $this->settings->get('blocked_source_domains') as $d) {
            $n = is_string($d) ? self::normalizeDomain($d) : null;
            if ($n !== null) {
                $global[] = $n;
            }
        }
        $block = array_values(array_unique(array_merge($global, $rules->where('mode', 'block')->pluck('domain')->all())));
        $prefer = array_values(array_diff($rules->where('mode', 'prefer')->pluck('domain')->all(), $block));

        return $this->cache[$workspaceId] = ['prefer' => $prefer, 'block' => $block];
    }

    public function blocked(string $workspaceId, string $url): bool
    {
        return self::matches(self::host($url), $this->lists($workspaceId)['block']);
    }

    public function preferred(string $workspaceId, string $url): bool
    {
        return ! $this->blocked($workspaceId, $url) && self::matches(self::host($url), $this->lists($workspaceId)['prefer']);
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
