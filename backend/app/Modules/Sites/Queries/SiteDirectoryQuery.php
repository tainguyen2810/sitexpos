<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Queries;

use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteContentItem;
use SiteXPost\Modules\Sites\Domain\SitePublication;
use SiteXPost\Modules\Sites\Domain\SiteTaxonomy;

final class SiteDirectoryQuery implements SiteDirectory
{
    public function find(string $siteId): ?array
    {
        $site = Site::query()->find($siteId);
        if ($site === null) {
            return null;
        }

        return [
            'id' => $site->id,
            'name' => $site->name,
            'url' => $site->url,
            'language' => $site->content_language,
            'status' => $site->status->value,
            'paired' => $site->status->isPaired(),
            'woocommerce' => (bool) ($site->capabilities['features']['woocommerce'] ?? false),
            'last_synced_at' => $site->last_synced_at?->toIso8601ZuluString(),
        ];
    }

    public function all(): array
    {
        return Site::query()->orderBy('name')->limit(500)->get()
            ->map(fn (Site $s): array => ['id' => $s->id, 'name' => $s->name, 'url' => $s->url, 'status' => $s->status->value, 'paired' => $s->status->isPaired()])
            ->values()->all();
    }

    public function content(string $siteId, array $types = ['post', 'page', 'product'], int $limit = 2000): array
    {
        return SiteContentItem::query()
            ->where('site_id', $siteId)
            ->whereIn('post_type', $types)
            ->where('status', 'publish')
            ->orderByDesc('published_at')
            ->limit(max(1, min(5000, $limit)))
            ->get()
            ->map(fn (SiteContentItem $i): array => [
                'id' => $i->id,
                'post_type' => $i->post_type,
                'title' => $i->title,
                'url' => $i->url,
                'excerpt' => $i->excerpt,
                'term_ids' => array_values(array_map('intval', $i->term_ids ?? [])),
                'product' => $i->product,
                'published_at' => $i->published_at?->toIso8601ZuluString(),
            ])->all();
    }

    public function taxonomies(string $siteId): array
    {
        return SiteTaxonomy::query()->where('site_id', $siteId)->orderByDesc('count')->limit(2000)->get()
            ->map(fn (SiteTaxonomy $t): array => [
                'taxonomy' => $t->taxonomy, 'wp_term_id' => $t->wp_term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count,
            ])->all();
    }

    public function publishingProfile(string $siteId): ?array
    {
        $site = Site::query()->find($siteId);
        if ($site === null) {
            return null;
        }
        $caps = $site->capabilities ?? [];
        $features = is_array($caps['features'] ?? null) ? $caps['features'] : [];
        $author = is_array($caps['author'] ?? null) ? $caps['author'] : [];
        $tz = is_string($caps['site']['timezone'] ?? null) ? $caps['site']['timezone'] : 'UTC';
        try {
            new \DateTimeZone($tz);
        } catch (\Exception) {
            $tz = 'UTC';
        }
        $adapter = is_string($features['seo_adapter'] ?? null) ? $features['seo_adapter'] : (($features['yoast'] ?? false) ? 'yoast' : (($features['rank_math'] ?? false) ? 'rank_math' : 'native'));

        return [
            'id' => $site->id, 'url' => $site->url, 'host' => $site->host(), 'status' => $site->status->value, 'paired' => $site->status->isPaired(),
            'timezone' => $tz, 'seo_adapter' => in_array($adapter, ['yoast', 'rank_math', 'native'], true) ? $adapter : 'native',
            'yoast' => (bool) ($features['yoast'] ?? false), 'rank_math' => (bool) ($features['rank_math'] ?? false), 'woocommerce' => (bool) ($features['woocommerce'] ?? false),
            'can_publish' => isset($author['can_publish']) ? (bool) $author['can_publish'] : null,
            'can_upload' => isset($author['can_upload']) ? (bool) $author['can_upload'] : null,
            'plugin_version' => $site->plugin_version, 'wp_version' => $site->wp_version, 'last_error_code' => $site->last_error_code,
        ];
    }

    public function contentIndex(string $siteId, array $types = ['post', 'page', 'product'], int $limit = 5000): array
    {
        return SiteContentItem::query()->where('site_id', $siteId)->whereIn('post_type', $types)
            ->orderByDesc('modified_at')->limit(max(1, min(10000, $limit)))->get()
            ->map(fn (SiteContentItem $i): array => [
                'wp_post_id' => (int) $i->wp_post_id, 'post_type' => $i->post_type, 'status' => $i->status, 'title' => $i->title,
                'url' => $i->url, 'slug' => $i->slug, 'is_managed' => (bool) $i->is_managed, 'modified_at' => $i->modified_at?->toIso8601ZuluString(),
            ])->all();
    }

    public function publication(string $siteId, string $sourceType, string $sourceId): ?array
    {
        $p = SitePublication::query()->where('site_id', $siteId)->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->whereNotNull('wp_post_id')->orderByDesc('updated_at')->first();

        return $p === null ? null : ['wp_post_id' => (int) $p->wp_post_id, 'url' => $p->url, 'wp_status' => $p->wp_status, 'idempotency_key' => $p->idempotency_key];
    }
}
