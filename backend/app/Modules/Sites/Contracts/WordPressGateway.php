<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Contracts;

use SiteXPost\Modules\Sites\Connector\ConnectorException;

/**
 * Signed connector operations for Phase 6 (Media, Publishing), addressed by
 * site id inside the current tenant. Every call goes through the Phase 2
 * client (SSRF guard, HMAC, response signature, activity log). Failures are
 * ConnectorException with a stable `errorCode` and a `retryable` flag.
 */
interface WordPressGateway
{
    /**
     * Create or update a post (idempotent by `idempotency_key`). See PublishPost::send().
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed> plugin answer incl. `state`
     *
     * @throws ConnectorException
     */
    public function publish(string $siteId, array $doc): array;

    /**
     * @return array<string, mixed>|null null when the post does not exist on WordPress
     *
     * @throws ConnectorException
     */
    public function postState(string $siteId, int $wpPostId): ?array;

    /**
     * @return array<string, mixed>|null
     *
     * @throws ConnectorException
     */
    public function lookup(string $siteId, string $idempotencyKey): ?array;

    /**
     * @param  array{alt?: string, title?: string, caption?: string}  $meta
     * @return array{id: int, url: string}
     *
     * @throws ConnectorException
     */
    public function uploadMedia(string $siteId, string $idempotencyKey, string $bytes, string $filename, array $meta): array;

    /**
     * @return array{items: list<array<string, mixed>>, total_pages: int}
     *
     * @throws ConnectorException
     */
    public function listMedia(string $siteId, int $page): array;
}
