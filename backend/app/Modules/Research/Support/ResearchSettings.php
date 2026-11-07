<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Research\Support;

use SiteXPost\Modules\Research\Domain\ResearchLimit;
use SiteXPost\Modules\Research\Domain\ResearchSourceSetting;

/**
 * Super Admin research configuration with safe defaults. Source settings are
 * platform rows (not tenant data); secrets stay encrypted and are only read
 * by the server-side adapters.
 */
final class ResearchSettings
{
    /**
     * Known sources. `requires_credentials` sources stay unavailable until a
     * Super Admin configures them; `policy` explains what is allowed.
     */
    public const SOURCES = [
        'wordpress' => ['label' => 'WordPress (bài viết, danh mục, thẻ, sản phẩm)', 'requires_credentials' => false, 'default_enabled' => true],
        'website_crawl' => ['label' => 'Thu thập trang công khai của website khách hàng', 'requires_credentials' => false, 'default_enabled' => true],
        'competitor_crawl' => ['label' => 'Thu thập trang công khai của đối thủ', 'requires_credentials' => false, 'default_enabled' => true],
        'search_console' => ['label' => 'Google Search Console (OAuth)', 'requires_credentials' => true, 'default_enabled' => true],
        'ai_expansion' => ['label' => 'AI mở rộng chủ đề ngành (qua AI Gateway)', 'requires_credentials' => false, 'default_enabled' => true],
        'dataforseo' => ['label' => 'DataForSEO (search volume, độ khó, gợi ý từ khóa, top 10 Google cho AI Writer)', 'requires_credentials' => true, 'default_enabled' => false],
        'evidence_fetch' => ['label' => 'Truy xuất nguồn bằng chứng cho AI Writer (Phase 5)', 'requires_credentials' => false, 'default_enabled' => true],
        'google_trends' => ['label' => 'Google Trends (chỉ qua API chính thức được cấp quyền)', 'requires_credentials' => true, 'default_enabled' => false],
    ];

    public const GLOBAL_DEFAULTS = [
        'user_agent' => 'SiteXPostBot/1.0 (+https://sitexpost.com/bot)',
        'crawl_delay_ms' => 1000,
        'max_page_bytes' => 2_000_000,
        'max_sitemap_urls' => 500,
        'site_crawl_enabled' => true,
        'competitor_crawl_enabled' => true,
        'snapshot_retention_days' => 90,
        'profile_refresh_days' => 7,
    ];

    public const LIMIT_DEFAULTS = [
        'max_campaigns' => 10,
        'max_runs_per_month' => 30,
        'max_ideas_per_run' => 50,
        'max_competitors_per_campaign' => 5,
        'max_calendar_items_per_month' => 60,
        'max_concurrent_runs' => 1,
        'max_ai_cost_per_run_micro_usd' => 2_000_000,
    ];

    /** @var array<string, ResearchSourceSetting|null> */
    private array $cache = [];

    public function source(string $source): ?ResearchSourceSetting
    {
        if (! array_key_exists($source, $this->cache)) {
            $this->cache[$source] = ResearchSourceSetting::query()->where('source', $source)->first();
        }

        return $this->cache[$source];
    }

    public function enabled(string $source): bool
    {
        $row = $this->source($source);

        return $row !== null ? $row->is_enabled : (bool) (self::SOURCES[$source]['default_enabled'] ?? false);
    }

    public function global(string $key): mixed
    {
        $config = $this->source('global')->config ?? [];

        return $config[$key] ?? self::GLOBAL_DEFAULTS[$key] ?? null;
    }

    public function timeout(string $source): int
    {
        return max(3, min(60, $this->source($source)->timeout_seconds ?? 15));
    }

    public function ratePerMinute(string $source): int
    {
        return max(1, min(600, $this->source($source)->rate_limit_per_minute ?? 30));
    }

    /**
     * @return array<string, int>
     */
    public function limitsFor(string $workspaceId): array
    {
        $global = ResearchLimit::query()->where('scope_key', 'global')->first();
        $override = ResearchLimit::query()->where('scope_key', 'ws:'.$workspaceId)->first();
        $out = [];
        foreach (self::LIMIT_DEFAULTS as $key => $default) {
            $out[$key] = (int) ($override?->getAttribute($key) ?? $global?->getAttribute($key) ?? $default);
        }

        return $out;
    }

    public function recordFailure(string $source, string $code): void
    {
        $row = ResearchSourceSetting::query()->where('source', $source)->first();
        if ($row === null) {
            $row = new ResearchSourceSetting;
            $row->forceFill(['source' => $source, 'is_enabled' => (bool) (self::SOURCES[$source]['default_enabled'] ?? false)]);
        }
        $row->forceFill(['failure_count' => $row->failure_count + 1, 'last_failure_at' => now(), 'last_error_code' => mb_substr($code, 0, 64)])->save();
        $this->cache[$source] = $row;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
