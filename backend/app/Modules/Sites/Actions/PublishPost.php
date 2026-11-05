<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Connector\WordPressClient;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SitePublication;

/**
 * Creates or updates a post/page on WordPress with idempotency.
 *
 * The idempotency key identifies the logical document: the first call creates
 * the post, every later call (retry after timeout, new version) updates the
 * SAME post. Protection exists on both sides: the Cloud mapping
 * (site_publications) and the plugin's own key → post lookup, so even a retry
 * whose first response was lost cannot create a duplicate.
 *
 * Images: `images` are uploaded first (idempotent); `{{sitex-image:KEY}}`
 * placeholders in the HTML are replaced by the uploaded URL; `featured_image`
 * names the key used as featured image. Used by Phase 2 (connection test)
 * and later by Publishing (Phase 8).
 */
final class PublishPost
{
    public function __construct(
        private readonly WordPressClient $client,
        private readonly UploadMedia $uploadMedia,
    ) {}

    /**
     * @param  array{
     *     idempotency_key: string,
     *     post_type?: string,
     *     title: string,
     *     content_html: string,
     *     excerpt?: string,
     *     slug?: string,
     *     status?: string,
     *     categories?: list<int>,
     *     tags?: list<string>,
     *     seo?: array<string, mixed>,
     *     images?: list<array{key: string, bytes: string, filename: string, alt?: string, title?: string, caption?: string}>,
     *     featured_image?: string|null,
     *     source_type?: string|null,
     *     source_id?: string|null,
     * }  $doc
     *
     * @throws ConnectorException
     */
    public function handle(Site $site, array $doc): SitePublication
    {
        return $this->send($site, $doc)['publication'];
    }

    /**
     * Same as handle() and also returns the plugin's answer (content hash,
     * modified time, slug, SEO read-back) for Phase 6 sync state. Phase 6
     * extra keys: `expected` (state the update is based on → the plugin
     * answers 409 `conflict` when WordPress changed), `force` (overwrite
     * approved by a human), `schema` (JSON-LD or null), `featured_wp_media_id`
     * (existing attachment), `date_gmt`.
     *
     * @param  array<string, mixed>  $doc
     * @return array{publication: SitePublication, response: array<string, mixed>}
     *
     * @throws ConnectorException
     */
    public function send(Site $site, array $doc): array
    {
        $publication = SitePublication::query()->where('site_id', $site->id)->where('idempotency_key', $doc['idempotency_key'])->first() ?? new SitePublication;
        if (! $publication->exists) {
            $publication->forceFill([
                'site_id' => $site->id,
                'idempotency_key' => $doc['idempotency_key'],
                'post_type' => $doc['post_type'] ?? 'post',
                'source_type' => $doc['source_type'] ?? null,
                'source_id' => $doc['source_id'] ?? null,
                'status' => 'pending',
                'attempts' => 0,
            ])->save();
        }
        $publication->increment('attempts');

        try {
            $html = $doc['content_html'];
            $featuredId = isset($doc['featured_wp_media_id']) && (int) $doc['featured_wp_media_id'] > 0 ? (int) $doc['featured_wp_media_id'] : null;
            foreach ($doc['images'] ?? [] as $image) {
                $media = $this->uploadMedia->handle($site, $doc['idempotency_key'].':'.$image['key'], $image['bytes'], $image['filename'], [
                    'alt' => $image['alt'] ?? '', 'title' => $image['title'] ?? '', 'caption' => $image['caption'] ?? '',
                ]);
                $html = str_replace('{{sitex-image:'.$image['key'].'}}', $media->url, $html);
                $html = str_replace('{{sitex-image-id:'.$image['key'].'}}', (string) $media->wp_media_id, $html);
                if (($doc['featured_image'] ?? null) === $image['key']) {
                    $featuredId = $media->wp_media_id;
                }
            }

            $payload = [
                'idempotency_key' => $doc['idempotency_key'],
                'post_type' => $publication->post_type,
                'title' => $doc['title'],
                'content_html' => $html,
                'excerpt' => $doc['excerpt'] ?? '',
                'slug' => $doc['slug'] ?? '',
                'status' => $doc['status'] ?? (string) ($site->settings['default_post_status'] ?? 'draft'),
                'categories' => $doc['categories'] ?? [],
                'tags' => $doc['tags'] ?? [],
                'featured_media_id' => $featuredId,
                'seo' => $doc['seo'] ?? (object) [],
                'sitex' => ['source_type' => $doc['source_type'] ?? null, 'source_id' => $doc['source_id'] ?? null],
            ];
            foreach (['expected', 'force', 'schema', 'date_gmt'] as $extra) {
                if (array_key_exists($extra, $doc)) {
                    $payload[$extra] = $doc[$extra];
                }
            }

            $data = $publication->wp_post_id !== null
                ? $this->client->call($site, 'PUT', '/sitex-post/v1/posts/'.$publication->wp_post_id, [], $payload)
                : $this->client->call($site, 'POST', '/sitex-post/v1/posts', [], $payload);

            $wpId = (int) ($data['id'] ?? 0);
            if ($wpId <= 0) {
                throw new ConnectorException('invalid_response', __('sites.errors.invalid_response'));
            }

            $publication->forceFill([
                'wp_post_id' => $wpId,
                'wp_status' => is_string($data['status'] ?? null) ? $data['status'] : null,
                'url' => is_string($data['url'] ?? null) ? mb_substr($data['url'], 0, 1000) : null,
                'payload_hash' => hash('sha256', (string) json_encode($payload)),
                'status' => 'succeeded',
                'last_error_code' => null,
                'published_at' => $publication->published_at ?? now(),
            ])->save();

            return ['publication' => $publication, 'response' => $data];
        } catch (ConnectorException $e) {
            $publication->forceFill(['status' => 'failed', 'last_error_code' => $e->errorCode])->save();
            throw $e;
        }
    }
}
