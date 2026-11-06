<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Connector\WordPressClient;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteMedia;

/**
 * Pushes image bytes to the plugin (`POST /media`, raw body). The plugin
 * never downloads URLs. Idempotent: the same key returns the same WP media
 * on both sides (Cloud mapping + plugin post meta).
 */
final class UploadMedia
{
    public const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private readonly WordPressClient $client) {}

    /**
     * @param  array{alt?: string, title?: string, caption?: string}  $meta
     *
     * @throws ConnectorException
     */
    public function handle(Site $site, string $idempotencyKey, string $bytes, string $filename, array $meta = []): SiteMedia
    {
        $existing = SiteMedia::query()->where('site_id', $site->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw new ConnectorException('unsupported_media_type', __('sites.errors.unsupported_media_type'));
        }
        if (strlen($bytes) > (int) config('sitex.connector.media_max_bytes')) {
            throw new ConnectorException('media_too_large', __('sites.errors.media_too_large'));
        }

        $data = $this->client->upload($site, '/sitex-post/v1/media', [
            'idempotency_key' => $idempotencyKey,
            'filename' => mb_substr(basename($filename), 0, 120),
            'alt' => mb_substr($meta['alt'] ?? '', 0, 250),
            'title' => mb_substr($meta['title'] ?? '', 0, 200),
            'caption' => mb_substr($meta['caption'] ?? '', 0, 500),
        ], $bytes, $mime);

        $mediaId = (int) ($data['id'] ?? 0);
        $url = is_string($data['url'] ?? null) ? $data['url'] : '';
        if ($mediaId <= 0 || $url === '') {
            throw new ConnectorException('invalid_response', __('sites.errors.invalid_response'));
        }

        $media = new SiteMedia;
        $media->forceFill([
            'site_id' => $site->id,
            'idempotency_key' => $idempotencyKey,
            'sha256' => hash('sha256', $bytes),
            'mime' => $mime,
            'bytes' => strlen($bytes),
            'wp_media_id' => $mediaId,
            'url' => mb_substr($url, 0, 1000),
        ])->save();

        return $media;
    }
}
