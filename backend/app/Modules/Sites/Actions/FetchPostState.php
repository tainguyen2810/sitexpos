<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Connector\WordPressClient;
use SiteXPost\Modules\Sites\Domain\Site;

/**
 * Current state of a post on WordPress (Phase 6 conflict detection and
 * reconciliation): `GET /posts/{id}/state` or `GET /posts/lookup?idempotency_key=`.
 * Returns null when WordPress has no such post (404 `post_not_found`).
 */
final class FetchPostState
{
    public function __construct(private readonly WordPressClient $client) {}

    /**
     * @return array<string, mixed>|null
     *
     * @throws ConnectorException
     */
    public function byId(Site $site, int $wpPostId): ?array
    {
        return $this->get($site, '/sitex-post/v1/posts/'.$wpPostId.'/state', []);
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws ConnectorException
     */
    public function byKey(Site $site, string $idempotencyKey): ?array
    {
        return $this->get($site, '/sitex-post/v1/posts/lookup', ['idempotency_key' => $idempotencyKey]);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>|null
     */
    private function get(Site $site, string $route, array $query): ?array
    {
        try {
            $data = $this->client->call($site, 'GET', $route, $query);
        } catch (ConnectorException $e) {
            if (in_array($e->errorCode, ['post_not_found', 'not_found'], true)) {
                return null;
            }
            throw $e;
        }

        return $data;
    }
}
