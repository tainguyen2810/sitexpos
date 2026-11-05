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
}
