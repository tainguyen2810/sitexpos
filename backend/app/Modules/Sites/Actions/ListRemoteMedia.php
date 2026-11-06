<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Connector\WordPressClient;
use SiteXPost\Modules\Sites\Domain\Site;

/**
 * Lists image attachments of the WordPress Media Library (metadata only,
 * `GET /media?page=`); Phase 6 Media Library imports them as references.
 */
final class ListRemoteMedia
{
    public function __construct(private readonly WordPressClient $client) {}

    /**
     * @return array{items: list<array<string, mixed>>, total_pages: int}
     *
     * @throws ConnectorException
     */
    public function page(Site $site, int $page = 1, int $perPage = 100): array
    {
        $data = $this->client->call($site, 'GET', '/sitex-post/v1/media', ['page' => max(1, $page), 'per_page' => max(1, min(100, $perPage))]);
        $items = array_values(array_filter(is_array($data['items'] ?? null) ? $data['items'] : [], 'is_array'));

        return ['items' => $items, 'total_pages' => max(1, (int) ($data['total_pages'] ?? 1))];
    }
}
