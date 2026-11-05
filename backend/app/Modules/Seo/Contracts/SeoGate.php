<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Seo\Contracts;

/**
 * What Publishing needs from the Seo module: the exact publishable render,
 * the WordPress SEO payload, schema, and the audit gate. Read / compute only.
 */
interface SeoGate
{
    /**
     * @return array<string, mixed> PublishableRenderer::render()
     */
    public function render(string $articleId, bool $forPublish): array;

    /**
     * Metadata as stored (generated on first access).
     *
     * @return array<string, mixed>
     */
    public function metadata(string $articleId): array;

    /**
     * `seo` payload for the connector (title, description, focus_keyword, canonical, noindex).
     *
     * @return array<string, mixed>
     */
    public function wordpressSeo(string $articleId): array;

    /**
     * @param  array{url?: string|null, image_url?: string|null, published_at?: string|null, modified_at?: string|null}  $extra
     * @return array{enabled: bool, types: list<string>, skipped: array<string, string>, graph: list<array<string, mixed>>}
     */
    public function schema(string $articleId, array $extra = []): array;

    /**
     * Runs a fresh audit of the current publishable render (no outbound requests).
     *
     * @return array{id: string, score: int, blocked: int, errors: int, warnings: int, content_hash: string, links_checked: bool, issues: list<array<string, mixed>>}
     */
    public function audit(string $articleId, ?string $userId): array;

    /**
     * @return array{id: string, score: int, blocked: int, errors: int, warnings: int, content_hash: string, links_checked: bool, created_at: string|null}|null
     */
    public function latestAudit(string $articleId): ?array;
}
