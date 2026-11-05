<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Contracts;

/**
 * Media facts for Seo and Publishing (tenant-scoped, plain arrays).
 * `markUploaded` is the only write and it stays inside the Media module.
 */
interface MediaLibrary
{
    /**
     * Placements of an article ordered by position.
     *
     * @return list<array{id: string, slot: string, section_key: string|null, section_heading: string|null, position: int, status: string,
     *     alt_text: string|null, caption: string|null, asset: array<string, mixed>|null}>
     */
    public function placements(string $articleId, bool $acceptedOnly = true): array;

    /**
     * @return array{id: string, site_id: string|null, source_type: string, status: string, mime: string|null, width: int|null, height: int|null,
     *     bytes: int|null, license: string, attribution: string|null, is_ai_generated: bool, title: string|null, alt_text: string|null,
     *     caption: string|null, wp_media_id: int|null, source_url: string|null, preview_url: string|null}|null
     */
    public function asset(string $assetId): ?array;

    /**
     * Optimized bytes to push to WordPress (null for remote-only assets).
     *
     * @return array{bytes: string, mime: string, filename: string}|null
     */
    public function bytes(string $assetId): ?array;

    public function markUploaded(string $assetId, string $siteId, int $wpMediaId, string $url): void;
}
