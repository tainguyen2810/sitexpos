<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Contracts;

/**
 * Phase 8 — read-only view of what SiteX published to WordPress (article
 * ↔ WordPress post ↔ URL) plus a live, signed conflict check before a
 * content refresh. Tenant-scoped; never writes Publishing rows except the
 * sync-state audit trail that CheckMappingSync also writes.
 */
interface PublicationDirectory
{
    /**
     * Every mapping of a site (current and historical: a slug change keeps
     * the old URL in history so old Search Console rows still map).
     *
     * @return list<array{article_id: string, wp_post_id: int|null, url: string|null, slug: string|null, wp_status: string|null, is_current: bool,
     *     last_published_at: string|null, last_published_version_id: string|null}>
     */
    public function forSite(string $siteId): array;

    /**
     * @return array{article_id: string, site_id: string, wp_post_id: int|null, url: string|null, wp_status: string|null,
     *     last_published_at: string|null, last_published_version_id: string|null, last_synced_hash: string|null}|null
     */
    public function current(string $articleId): ?array;

    /**
     * Ask WordPress (signed connector) for the post state and compare it
     * with what SiteX last wrote. `conflicts` uses ConflictDetector types
     * (manual_edit, revision_changed, slug_changed, seo_modified,
     * post_missing, domain_changed); `reachable` false when the site did not
     * answer.
     *
     * @return array{reachable: bool, error_code: string|null, conflicts: list<string>, state: array<string, mixed>|null}
     */
    public function remoteCheck(string $articleId): array;
}
