<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Queries;

use SiteXPost\Modules\Publishing\Contracts\PublicationDirectory;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Support\ConflictDetector;
use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Sites\Contracts\WordPressGateway;

final class PublicationDirectoryQuery implements PublicationDirectory
{
    public function __construct(private readonly WordPressGateway $wp, private readonly SiteDirectory $sites) {}

    public function forSite(string $siteId): array
    {
        return WordPressPostMapping::query()->where('site_id', $siteId)->orderByDesc('is_current')->orderByDesc('updated_at')->limit(20000)->get()
            ->map(fn (WordPressPostMapping $m): array => [
                'article_id' => $m->article_id, 'wp_post_id' => $m->wp_post_id !== null ? (int) $m->wp_post_id : null, 'url' => $m->url,
                'slug' => $m->slug, 'wp_status' => $m->wp_status, 'is_current' => (bool) $m->is_current,
                'last_published_at' => $m->last_published_at?->toIso8601ZuluString(), 'last_published_version_id' => $m->last_published_version_id,
            ])->all();
    }

    public function current(string $articleId): ?array
    {
        $m = $this->mapping($articleId);

        return $m === null ? null : [
            'article_id' => $m->article_id, 'site_id' => $m->site_id, 'wp_post_id' => $m->wp_post_id !== null ? (int) $m->wp_post_id : null,
            'url' => $m->url, 'wp_status' => $m->wp_status, 'last_published_at' => $m->last_published_at?->toIso8601ZuluString(),
            'last_published_version_id' => $m->last_published_version_id, 'last_synced_hash' => $m->last_synced_hash,
        ];
    }

    public function remoteCheck(string $articleId): array
    {
        $m = $this->mapping($articleId);
        if ($m === null || $m->wp_post_id === null) {
            return ['reachable' => true, 'error_code' => 'not_published', 'conflicts' => [], 'state' => null];
        }
        $host = (string) ($this->sites->publishingProfile($m->site_id)['host'] ?? $m->site_host);
        try {
            $state = $this->wp->postState($m->site_id, (int) $m->wp_post_id);
        } catch (ConnectorException $e) {
            ConflictDetector::record($m, null, 'unreachable', ['error' => $e->errorCode, 'source' => 'analytics_refresh']);

            return ['reachable' => false, 'error_code' => $e->errorCode, 'conflicts' => [], 'state' => null];
        }
        $types = ConflictDetector::types($m, $state, $host);
        ConflictDetector::record($m, $state, $types === [] ? 'in_sync' : ($types[0] === 'post_missing' ? 'missing' : $types[0]), ['types' => $types, 'source' => 'analytics_refresh']);

        return ['reachable' => true, 'error_code' => null, 'conflicts' => $types,
            'state' => $state === null ? null : array_intersect_key($state, array_flip(['id', 'status', 'slug', 'url', 'modified_gmt', 'content_hash', 'revision_id']))];
    }

    private function mapping(string $articleId): ?WordPressPostMapping
    {
        return WordPressPostMapping::query()->where('article_id', $articleId)->where('is_current', true)->first();
    }
}
