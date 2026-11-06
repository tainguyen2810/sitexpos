<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Connector;

use SiteXPost\Modules\Sites\Actions\FetchPostState;
use SiteXPost\Modules\Sites\Actions\ListRemoteMedia;
use SiteXPost\Modules\Sites\Actions\PublishPost;
use SiteXPost\Modules\Sites\Actions\UploadMedia;
use SiteXPost\Modules\Sites\Contracts\WordPressGateway;
use SiteXPost\Modules\Sites\Domain\Site;

final class WordPressGatewayService implements WordPressGateway
{
    public function __construct(
        private readonly PublishPost $publish,
        private readonly FetchPostState $state,
        private readonly UploadMedia $upload,
        private readonly ListRemoteMedia $media,
    ) {}

    public function publish(string $siteId, array $doc): array
    {
        return $this->publish->send($this->site($siteId), $doc)['response'];
    }

    public function postState(string $siteId, int $wpPostId): ?array
    {
        return $this->state->byId($this->site($siteId), $wpPostId);
    }

    public function lookup(string $siteId, string $idempotencyKey): ?array
    {
        return $this->state->byKey($this->site($siteId), $idempotencyKey);
    }

    public function uploadMedia(string $siteId, string $idempotencyKey, string $bytes, string $filename, array $meta): array
    {
        $m = $this->upload->handle($this->site($siteId), $idempotencyKey, $bytes, $filename, $meta);

        return ['id' => (int) $m->wp_media_id, 'url' => (string) $m->url];
    }

    public function listMedia(string $siteId, int $page): array
    {
        return $this->media->page($this->site($siteId), $page);
    }

    private function site(string $siteId): Site
    {
        $site = Site::query()->find($siteId);
        if ($site === null) {
            throw new ConnectorException('site_not_found', __('sites.errors.not_connected'));
        }
        if (! $site->status->isPaired()) {
            throw new ConnectorException('not_connected', __('sites.errors.not_connected'));
        }

        return $site;
    }
}
