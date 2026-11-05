<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Queries;

use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteContentItem;
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
}
