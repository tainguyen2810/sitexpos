<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SitePublication;

/**
 * Connection test for writes: creates (first run) or updates (every later
 * run, same idempotency key) ONE draft post with a featured image, an inline
 * image and SEO metadata. Proves create, update, media upload and SEO sync
 * without touching real content. The post stays a draft.
 */
final class TestPublish
{
    public const IDEMPOTENCY_KEY = 'sitex-connection-test';

    public function __construct(private readonly PublishPost $publish) {}

    /**
     * @throws ConnectorException
     */
    public function handle(Site $site): SitePublication
    {
        $stamp = now()->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i');
        $title = __('sites.test_post.title');

        $publication = $this->publish->handle($site, [
            'idempotency_key' => self::IDEMPOTENCY_KEY,
            'post_type' => 'post',
            'title' => $title,
            'status' => 'draft',
            'excerpt' => __('sites.test_post.excerpt'),
            'content_html' => '<p>'.e(__('sites.test_post.body', ['time' => $stamp])).'</p>'
                .'<figure class="wp-block-image"><img src="{{sitex-image:inline}}" alt="'.e(__('sites.test_post.inline_alt')).'" class="wp-image-{{sitex-image-id:inline}}" /></figure>'
                .'<p>'.e(__('sites.test_post.footer')).'</p>',
            'tags' => ['sitex-post-test'],
            'seo' => [
                'title' => $title.' | SiteX Post',
                'description' => __('sites.test_post.seo_description'),
                'focus_keyword' => 'sitex post',
                'noindex' => true,
            ],
            'images' => [
                ['key' => 'featured', 'bytes' => self::png(1200, 630, [37, 99, 235]), 'filename' => 'sitex-test-featured.png', 'alt' => __('sites.test_post.featured_alt')],
                ['key' => 'inline', 'bytes' => self::png(800, 400, [16, 185, 129]), 'filename' => 'sitex-test-inline.png', 'alt' => __('sites.test_post.inline_alt')],
            ],
            'featured_image' => 'featured',
            'source_type' => 'connection_test',
        ]);

        Audit::record('site.test_published', $site, ['wp_post_id' => $publication->wp_post_id, 'attempts' => $publication->attempts]);

        return $publication;
    }

    /**
     * Small solid-colour PNG generated locally (no external image source).
     *
     * @param  array{0:int,1:int,2:int}  $rgb
     */
    public static function png(int $width, int $height, array $rgb): string
    {
        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            throw new ConnectorException('image_generation_failed');
        }
        imagefill($img, 0, 0, (int) imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]));
        $white = (int) imagecolorallocate($img, 255, 255, 255);
        imagestring($img, 5, 24, 24, 'SiteX Post - connection test', $white);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
