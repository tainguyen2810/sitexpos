<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Analytics\Domain\SearchPageMetric;
use SiteXPost\Modules\Analytics\Domain\UrlMapping;
use SiteXPost\Modules\Publishing\Contracts\PublicationDirectory;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

/**
 * Article ↔ URL mapping (ARTICLE PERFORMANCE TRACKING). Sources, strongest
 * first: the current WordPress mapping written by the Publisher, previous
 * URLs of the same article (slug changes), the synced WordPress inventory
 * (wp_post_id). Matching is exact on the normalized URL; a URL that two
 * articles claim is left unmapped instead of guessing.
 */
final class UrlMapper
{
    public function __construct(
        private readonly PublicationDirectory $publications,
        private readonly SiteDirectory $sites,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array<string, string> page_hash → article_id
     */
    public function refresh(string $siteId): array
    {
        $byHash = [];
        $conflicts = [];
        $add = function (?string $url, ?string $articleId, ?int $wpPostId, string $source, bool $current) use (&$byHash, &$conflicts): void {
            if ($url === null || $url === '') {
                return;
            }
            $hash = UrlNormalizer::hash($url);
            if (isset($byHash[$hash]) && $articleId !== null && $byHash[$hash]['article_id'] !== null && $byHash[$hash]['article_id'] !== $articleId) {
                $conflicts[$hash] = true;

                return;
            }
            if (! isset($byHash[$hash]) || ($byHash[$hash]['article_id'] === null && $articleId !== null)) {
                $byHash[$hash] = ['url' => UrlNormalizer::normalize($url), 'article_id' => $articleId, 'wp_post_id' => $wpPostId, 'source' => $source, 'current' => $current];
            }
        };
        $pubs = $this->publications->forSite($siteId);
        $byPost = [];
        foreach ($pubs as $p) {
            $add($p['url'], $p['article_id'], $p['wp_post_id'], $p['is_current'] ? 'publisher' : 'publisher_history', $p['is_current']);
            if ($p['wp_post_id'] !== null) {
                $byPost[$p['wp_post_id']] ??= $p['article_id'];
            }
        }
        foreach ($this->sites->contentIndex($siteId, ['post', 'page', 'product'], 20000) as $item) {
            if ($item['status'] !== 'publish') {
                continue;
            }
            $add($item['url'], $byPost[$item['wp_post_id']] ?? null, $item['wp_post_id'], 'inventory', true);
        }
        foreach (array_keys($conflicts) as $hash) {
            $byHash[$hash]['article_id'] = null;
        }

        $ws = $this->context->workspaceId();
        $now = now();
        DB::transaction(function () use ($siteId, $byHash, $ws, $now): void {
            foreach (array_chunk($byHash, 500, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $hash => $m) {
                    $rows[] = ['id' => strtolower((string) Str::ulid()), 'workspace_id' => $ws, 'site_id' => $siteId, 'page_hash' => $hash, 'page_url' => mb_substr($m['url'], 0, 1000),
                        'article_id' => $m['article_id'], 'wp_post_id' => $m['wp_post_id'], 'source' => $m['source'], 'is_current' => $m['current'], 'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('analytics_url_mappings')->upsert($rows, ['site_id', 'page_hash'], ['page_url', 'article_id', 'wp_post_id', 'source', 'is_current', 'updated_at']);
            }
        });

        $map = UrlMapping::query()->where('site_id', $siteId)->whereNotNull('article_id')->pluck('article_id', 'page_hash')->all();
        // Re-attach metric rows (a URL can become mapped after its data was synced).
        foreach (array_chunk($map, 300, true) as $chunk) {
            foreach ($chunk as $hash => $articleId) {
                SearchPageMetric::query()->where('site_id', $siteId)->where('page_hash', $hash)->where(fn ($q) => $q->whereNull('article_id')->orWhere('article_id', '!=', $articleId))
                    ->update(['article_id' => $articleId]);
            }
        }

        return $map;
    }
}
