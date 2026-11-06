<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Contracts;

/**
 * Read-only view of connected sites for other modules (Phase 4 research).
 * Results are tenant-scoped by the current TenantContext; plain arrays so
 * consumers never write to Sites tables.
 */
interface SiteDirectory
{
    /**
     * @return array{id: string, name: string, url: string, language: string, status: string, paired: bool, woocommerce: bool, last_synced_at: string|null}|null
     */
    public function find(string $siteId): ?array;

    /**
     * Every site of the current workspace (Phase 8 analytics site picker).
     *
     * @return list<array{id: string, name: string, url: string, status: string, paired: bool}>
     */
    public function all(): array;

    /**
     * Published posts/pages/products of the site (synced index).
     *
     * @param  list<string>  $types
     * @return list<array{id: string, post_type: string, title: string, url: string|null, excerpt: string|null, term_ids: list<int>, product: array<string, mixed>|null, published_at: string|null}>
     */
    public function content(string $siteId, array $types = ['post', 'page', 'product'], int $limit = 2000): array;

    /**
     * @return list<array{taxonomy: string, wp_term_id: int, name: string, slug: string, count: int}>
     */
    public function taxonomies(string $siteId): array;

    /**
     * Publishing view of a site (Phase 6): connection state, detected SEO
     * plugin, timezone reported by WordPress, publish/upload rights of the
     * publishing account.
     *
     * @return array{id: string, url: string, host: string, status: string, paired: bool, timezone: string, seo_adapter: string,
     *     yoast: bool, rank_math: bool, woocommerce: bool, can_publish: bool|null, can_upload: bool|null, plugin_version: string|null,
     *     wp_version: string|null, last_error_code: string|null}|null
     */
    public function publishingProfile(string $siteId): ?array;

    /**
     * Every synced item (any status), with slug and WordPress id, for
     * internal-link validation and conflict checks.
     *
     * @param  list<string>  $types
     * @return list<array{wp_post_id: int, post_type: string, status: string, title: string, url: string|null, slug: string|null, is_managed: bool, modified_at: string|null}>
     */
    public function contentIndex(string $siteId, array $types = ['post', 'page', 'product'], int $limit = 5000): array;

    /**
     * Latest WordPress post created through the connector for a source
     * (e.g. source_type "article"), or null.
     *
     * @return array{wp_post_id: int, url: string|null, wp_status: string|null, idempotency_key: string}|null
     */
    public function publication(string $siteId, string $sourceType, string $sourceId): ?array;
}
