<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use Illuminate\Support\Carbon;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Connector\WordPressClient;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteContentItem;
use SiteXPost\Modules\Sites\Domain\SiteTaxonomy;

/**
 * Full sync of taxonomies and the content index (posts, pages and
 * WooCommerce products when WooCommerce is active). Rows that no longer
 * exist on WordPress are removed. Read-only on the WordPress side.
 */
final class SyncSite
{
    public const TAXONOMIES = ['category', 'post_tag'];

    public const PRODUCT_TAXONOMIES = ['product_cat', 'product_tag'];

    public const CONTENT_TYPES = ['post', 'page'];

    public function __construct(private readonly WordPressClient $client) {}

    /**
     * @param  (callable(int, string): void)|null  $progress
     * @return array{taxonomies: int, content: array<string, int>}
     *
     * @throws ConnectorException
     */
    public function handle(Site $site, ?callable $progress = null): array
    {
        if (! $site->status->isPaired()) {
            throw new ConnectorException('not_connected', __('sites.errors.not_connected'));
        }

        $woo = (bool) ($site->capabilities['features']['woocommerce'] ?? false);
        $taxonomies = $woo ? array_merge(self::TAXONOMIES, self::PRODUCT_TAXONOMIES) : self::TAXONOMIES;
        $types = $woo ? array_merge(self::CONTENT_TYPES, ['product']) : self::CONTENT_TYPES;

        // Rows not seen in this run are removed, but only for listings that
        // were read completely (a listing cut at sync_max_pages keeps its rows).
        $termCount = 0;
        foreach ($taxonomies as $taxonomy) {
            $progress && $progress(10, 'taxonomies:'.$taxonomy);
            $seen = [];
            [$count, $complete] = $this->paginate($site, '/sitex-post/v1/terms', ['taxonomy' => $taxonomy], function (array $item) use ($site, $taxonomy, &$seen): void {
                if (($id = $this->upsertTerm($site, $taxonomy, $item)) !== null) {
                    $seen[] = $id;
                }
            });
            $termCount += $count;
            if ($complete) {
                SiteTaxonomy::query()->where('site_id', $site->id)->where('taxonomy', $taxonomy)->whereNotIn('wp_term_id', $seen)->delete();
            }
        }
        SiteTaxonomy::query()->where('site_id', $site->id)->whereNotIn('taxonomy', $taxonomies)->delete();

        $content = [];
        foreach ($types as $i => $type) {
            $progress && $progress(30 + (int) (60 * $i / count($types)), 'content:'.$type);
            $seen = [];
            [$content[$type], $complete] = $this->paginate($site, '/sitex-post/v1/content', ['type' => $type], function (array $item) use ($site, $type, &$seen): void {
                if (($id = $this->upsertContent($site, $type, $item)) !== null) {
                    $seen[] = $id;
                }
            });
            if ($complete) {
                SiteContentItem::query()->where('site_id', $site->id)->where('post_type', $type)->whereNotIn('wp_post_id', $seen)->delete();
            }
        }
        SiteContentItem::query()->where('site_id', $site->id)->whereNotIn('post_type', $types)->delete();

        $site->forceFill(['last_synced_at' => now()])->save();
        Audit::record('site.synced', $site, ['taxonomies' => $termCount, 'content' => $content]);

        return ['taxonomies' => $termCount, 'content' => $content];
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  callable(array<string, mixed>): void  $each
     * @return array{0: int, 1: bool} item count, and whether every page was read
     */
    private function paginate(Site $site, string $route, array $query, callable $each): array
    {
        $count = 0;
        $maxPages = (int) config('sitex.connector.sync_max_pages', 50);

        for ($page = 1; $page <= $maxPages; $page++) {
            $data = $this->client->call($site, 'GET', $route, $query + ['page' => $page, 'per_page' => 100]);
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];

            foreach ($items as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    $each($item);
                    $count++;
                }
            }

            if ($page >= (int) ($data['total_pages'] ?? 1) || $items === []) {
                return [$count, true];
            }
        }

        return [$count, false];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsertTerm(Site $site, string $taxonomy, array $item): ?int
    {
        $termId = (int) ($item['id'] ?? 0);
        if ($termId <= 0) {
            return null;
        }

        $term = SiteTaxonomy::query()->where(['site_id' => $site->id, 'taxonomy' => $taxonomy, 'wp_term_id' => $termId])->first() ?? new SiteTaxonomy;
        $term->forceFill([
            'site_id' => $site->id,
            'taxonomy' => $taxonomy,
            'wp_term_id' => $termId,
            'name' => mb_substr(self::text($item['name'] ?? ''), 0, 200),
            'slug' => mb_substr(self::text($item['slug'] ?? ''), 0, 200),
            'parent_wp_id' => ((int) ($item['parent'] ?? 0)) ?: null,
            'count' => max(0, (int) ($item['count'] ?? 0)),
            'synced_at' => now(),
        ])->save();

        return $termId;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsertContent(Site $site, string $type, array $item): ?int
    {
        $postId = (int) ($item['id'] ?? 0);
        if ($postId <= 0) {
            return null;
        }

        $product = null;
        if ($type === 'product' && is_array($item['product'] ?? null)) {
            $p = $item['product'];
            $product = [
                'sku' => mb_substr(self::text($p['sku'] ?? ''), 0, 100),
                'price' => mb_substr(self::text($p['price'] ?? ''), 0, 32),
                'regular_price' => mb_substr(self::text($p['regular_price'] ?? ''), 0, 32),
                'sale_price' => mb_substr(self::text($p['sale_price'] ?? ''), 0, 32),
                'currency' => mb_substr(self::text($p['currency'] ?? ''), 0, 8),
                'stock_status' => mb_substr(self::text($p['stock_status'] ?? ''), 0, 32),
                'type' => mb_substr(self::text($p['type'] ?? ''), 0, 32),
                'image_ids' => array_slice(array_values(array_filter(array_map('intval', is_array($p['image_ids'] ?? null) ? $p['image_ids'] : []), fn (int $i) => $i > 0)), 0, 5),
            ];
        }

        // Plugin ≥ 1.2.0 sends the author (id + public display name); older plugins send nothing.
        $author = is_array($item['author'] ?? null) ? $item['author'] : [];
        $authorId = (int) ($author['id'] ?? 0);
        $authorName = mb_substr(self::text($author['name'] ?? ''), 0, 200);

        $termIds = array_values(array_filter(array_map('intval', is_array($item['term_ids'] ?? null) ? $item['term_ids'] : [])));

        $row = SiteContentItem::query()->where(['site_id' => $site->id, 'post_type' => $type, 'wp_post_id' => $postId])->first() ?? new SiteContentItem;
        $row->forceFill([
            'site_id' => $site->id,
            'post_type' => $type,
            'wp_post_id' => $postId,
            'status' => mb_substr(self::text($item['status'] ?? ''), 0, 20),
            'title' => mb_substr(self::text($item['title'] ?? ''), 0, 500),
            'url' => self::url($item['url'] ?? null, $site),
            'slug' => mb_substr(self::text($item['slug'] ?? ''), 0, 200),
            'excerpt' => mb_substr(self::text($item['excerpt'] ?? ''), 0, 1000),
            'term_ids' => $termIds,
            'product' => $product,
            'is_managed' => (bool) ($item['managed'] ?? false),
            'author_wp_id' => $authorId > 0 ? $authorId : null,
            'author_name' => $authorId > 0 && $authorName !== '' ? $authorName : null,
            'published_at' => self::date($item['date_gmt'] ?? null),
            'modified_at' => self::date($item['modified_gmt'] ?? null),
            'synced_at' => now(),
        ])->save();

        return $postId;
    }

    /** Plain text only: data coming from WordPress is untrusted. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5)) : '';
    }

    private static function url(mixed $value, Site $site): ?string
    {
        if (! is_string($value) || ! preg_match('#^https?://#i', $value)) {
            return null;
        }

        return parse_url($value, PHP_URL_HOST) === $site->host() ? mb_substr($value, 0, 1000) : null;
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
