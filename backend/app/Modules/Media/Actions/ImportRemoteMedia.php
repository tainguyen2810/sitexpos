<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Actions;

use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Domain\MediaSource;
use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Sites\Contracts\WordPressGateway;

/**
 * Imports the WordPress Media Library as REFERENCES (id, URL, size, alt):
 * no bytes are copied. Attachments used as WooCommerce product images
 * (product.image_ids from the content sync) become `woocommerce` assets
 * titled after the product — the only images allowed in the "real product
 * photo" slot. License: `wordpress_site` (owned by the customer's site).
 */
final class ImportRemoteMedia
{
    public const MAX_PAGES = 20;

    public function __construct(private readonly WordPressGateway $wp, private readonly SiteDirectory $sites) {}

    /**
     * @throws ConnectorException
     */
    public function handle(string $siteId): int
    {
        $source = $this->source($siteId, 'wordpress');
        $source->forceFill(['status' => 'syncing'])->save();

        $products = [];
        foreach ($this->sites->content($siteId, ['product'], 2000) as $p) {
            foreach ($p['product']['image_ids'] ?? [] as $imageId) {
                $products[(int) $imageId] ??= ['id' => $p['id'], 'title' => $p['title'], 'sku' => $p['product']['sku'] ?? null];
            }
        }

        $count = 0;
        try {
            $page = 1;
            do {
                $res = $this->wp->listMedia($siteId, $page);
                foreach ($res['items'] as $item) {
                    $count += $this->upsert($siteId, $item, $products[(int) ($item['id'] ?? 0)] ?? null) ? 1 : 0;
                }
                $page++;
            } while ($page <= min($res['total_pages'], self::MAX_PAGES));
        } catch (ConnectorException $e) {
            $source->forceFill(['status' => 'failed', 'last_error_code' => $e->errorCode])->save();
            throw $e;
        }

        $source->forceFill(['status' => 'idle', 'last_error_code' => null, 'last_synced_at' => now(), 'items_count' => $count])->save();
        if ($products !== []) {
            $this->source($siteId, 'woocommerce')->forceFill(['status' => 'idle', 'last_synced_at' => now(),
                'items_count' => MediaAsset::query()->where('site_id', $siteId)->where('source_type', 'woocommerce')->count()])->save();
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{id: string, title: string, sku: string|null}|null  $product
     */
    private function upsert(string $siteId, array $item, ?array $product): bool
    {
        $id = (int) ($item['id'] ?? 0);
        $url = is_string($item['url'] ?? null) ? $item['url'] : '';
        $mime = is_string($item['mime'] ?? null) ? $item['mime'] : '';
        if ($id <= 0 || ! preg_match('#^https?://#i', $url) || ! str_starts_with($mime, 'image/')) {
            return false;
        }
        $type = $product !== null ? 'woocommerce' : 'wordpress';
        $asset = MediaAsset::query()->where('site_id', $siteId)->whereIn('source_type', ['wordpress', 'woocommerce'])->where('source_ref', (string) $id)->first() ?? new MediaAsset;
        $text = static fn (mixed $v, int $max): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim(strip_tags($v)), 0, $max) : null;
        $asset->forceFill([
            'site_id' => $siteId, 'source_type' => $type, 'source_ref' => (string) $id, 'source_url' => mb_substr($url, 0, 1000),
            'original_filename' => mb_substr(basename((string) parse_url($url, PHP_URL_PATH)), 0, 190), 'mime' => mb_substr($mime, 0, 64),
            'width' => is_numeric($item['width'] ?? null) ? (int) $item['width'] : null, 'height' => is_numeric($item['height'] ?? null) ? (int) $item['height'] : null,
            'bytes' => is_numeric($item['filesize'] ?? null) ? (int) $item['filesize'] : null, 'license' => 'wordpress_site',
            'title' => $product !== null ? mb_substr($product['title'], 0, 200) : $text($item['title'] ?? null, 200),
            'alt_text' => $text($item['alt'] ?? null, 250), 'caption' => $text($item['caption'] ?? null, 500), 'wp_media_id' => $id, 'status' => 'ready',
            'metadata' => array_filter(['product' => $product, 'wp_managed' => (bool) ($item['managed'] ?? false)]),
        ])->save();

        return true;
    }

    private function source(string $siteId, string $type): MediaSource
    {
        return MediaSource::query()->where('site_id', $siteId)->where('type', $type)->first()
            ?? tap(new MediaSource)->forceFill(['site_id' => $siteId, 'type' => $type, 'enabled' => true]);
    }
}
