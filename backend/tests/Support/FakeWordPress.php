<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SiteXPost\Modules\Sites\Connector\Signer;

/**
 * In-memory stand-in for the SiteX Post WordPress plugin, used with
 * Http::fake(). It verifies the SITEX-HMAC-V1 request signature, enforces
 * timestamp/nonce rules and signs its responses exactly like the plugin, and
 * keeps idempotency state so retries can be asserted.
 */
final class FakeWordPress
{
    /** @var array<string, string> key id => secret */
    public array $keys = [];

    /** @var array<int, array<string, mixed>> */
    public array $posts = [];

    /** @var array<int, array<string, mixed>> */
    public array $media = [];

    /** @var array<string, true> */
    public array $nonces = [];

    /** @var list<array{method: string, route: string}> */
    public array $calls = [];

    public bool $online = true;

    public bool $tamperResponses = false;

    /** Corrupt only the response of this route (simulates a lost answer after WordPress acted). */
    public ?string $tamperRoute = null;

    public ?int $forceStatus = null;

    public bool $woocommerce = true;

    /** Phase 6: answer POST/PUT /posts after a delay longer than the client timeout (WordPress acted, Cloud saw a timeout). */
    public ?string $timeoutAfterWriteRoute = null;

    /** @var array<int, array<string, mixed>> Media Library attachments returned by GET /media. */
    public array $library = [];

    public string $seoAdapter = 'yoast';

    private int $nextId = 100;

    public function __construct(public string $baseUrl = 'https://blog.example.com') {}

    public function install(): self
    {
        Http::fake([rtrim($this->baseUrl, '/').'/*' => fn (Request $request) => $this->handle($request)]);

        return $this;
    }

    public function handle(Request $request): PromiseInterface
    {
        if (! $this->online) {
            throw new ConnectionException('offline');
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $route = (string) ($query['rest_route'] ?? parse_url($request->url(), PHP_URL_PATH));
        $route = (string) preg_replace('#^/wp-json#', '', $route);
        unset($query['rest_route']);
        $this->calls[] = ['method' => $request->method(), 'route' => $route];

        if ($this->forceStatus !== null) {
            return Http::response(['code' => 'boom', 'message' => 'forced'], $this->forceStatus);
        }

        $keyId = $request->header(Signer::HEADER_KEY)[0] ?? '';
        $ts = (int) ($request->header(Signer::HEADER_TIMESTAMP)[0] ?? 0);
        $nonce = $request->header(Signer::HEADER_NONCE)[0] ?? '';
        $sig = $request->header(Signer::HEADER_SIGNATURE)[0] ?? '';
        $secret = $this->keys[$keyId] ?? null;

        if ($secret === null) {
            return Http::response(['code' => 'sitex_invalid_signature', 'message' => 'Invalid'], 401);
        }
        $canonical = Signer::canonical(Signer::DIRECTION_TO_WP, $request->method(), $route, $query, $ts, $nonce, $request->body());
        if (abs(time() - $ts) > 300 || ! Signer::verify($canonical, $secret, $sig) || isset($this->nonces[$nonce])) {
            return Http::response(['code' => 'sitex_invalid_signature', 'message' => 'Invalid'], 401);
        }
        $this->nonces[$nonce] = true;

        [$status, $data] = $this->route($request, $route, $query, $keyId);
        if ($this->timeoutAfterWriteRoute !== null && str_starts_with($route, $this->timeoutAfterWriteRoute) && in_array($request->method(), ['POST', 'PUT'], true)) {
            $this->timeoutAfterWriteRoute = null; // only the first write times out

            throw new ConnectionException('timeout after write');
        }
        $body = (string) json_encode($data);
        $now = time();
        $signSecret = $this->keys[$keyId] ?? $secret;
        $headers = [
            Signer::HEADER_TIMESTAMP => (string) $now,
            Signer::HEADER_SIGNATURE => Signer::sign(Signer::responseCanonical($nonce, $now, ($this->tamperResponses || $this->tamperRoute === $route) ? $body.'x' : $body), $signSecret),
        ];

        return Http::response($body, $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function route(Request $request, string $route, array $query, string $keyId): array
    {
        $json = json_decode($request->body(), true) ?: [];

        return match (true) {
            $route === '/sitex-post/v1/health' => [200, [
                'ok' => true,
                'versions' => ['wordpress' => '6.8.3', 'php' => '8.2.0', 'plugin' => '1.0.0'],
                'features' => ['woocommerce' => $this->woocommerce, 'yoast' => true, 'rank_math' => false, 'seo_adapter' => 'yoast'],
                'author' => ['exists' => true, 'wp_user_id' => 2, 'display_name' => 'Author', 'can_publish' => true, 'can_upload' => true],
                'limits' => ['max_upload_bytes' => 10485760],
            ]],
            $route === '/sitex-post/v1/terms' => [200, $this->terms((string) $query['taxonomy'])],
            $route === '/sitex-post/v1/content' => [200, $this->content((string) $query['type'])],
            $route === '/sitex-post/v1/media' && $request->method() === 'GET' => [200, ['items' => array_values($this->library), 'total' => count($this->library), 'total_pages' => 1, 'page' => 1]],
            $route === '/sitex-post/v1/media' => $this->upload($request, $query),
            $route === '/sitex-post/v1/posts/lookup' => $this->lookup((string) ($query['idempotency_key'] ?? '')),
            (bool) preg_match('#^/sitex-post/v1/posts/(\d+)/state$#', $route, $sm) && $request->method() === 'GET' => isset($this->posts[(int) $sm[1]])
                ? [200, $this->state((int) $sm[1])] : [404, ['code' => 'sitex_post_not_found', 'message' => 'Post not found.']],
            $route === '/sitex-post/v1/posts' && $request->method() === 'POST' => $this->upsert($json, null),
            (bool) preg_match('#^/sitex-post/v1/posts/(\d+)$#', $route, $m) && $request->method() === 'PUT' => $this->upsert($json, (int) $m[1]),
            $route === '/sitex-post/v1/credentials/rotate' => $this->rotate($json, $keyId),
            $route === '/sitex-post/v1/disconnect' => [200, ['disconnected' => true]],
            default => [404, ['code' => 'rest_no_route']],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function terms(string $taxonomy): array
    {
        $items = match ($taxonomy) {
            'category' => [['id' => 1, 'name' => 'Tin tức', 'slug' => 'tin-tuc', 'parent' => 0, 'count' => 3], ['id' => 2, 'name' => '<b>Hướng dẫn</b>', 'slug' => 'huong-dan', 'parent' => 1, 'count' => 1]],
            'post_tag' => [['id' => 5, 'name' => 'seo', 'slug' => 'seo', 'parent' => 0, 'count' => 2]],
            'product_cat' => [['id' => 9, 'name' => 'Áo', 'slug' => 'ao', 'parent' => 0, 'count' => 1]],
            default => [],
        };

        return ['items' => $items, 'total' => count($items), 'total_pages' => 1, 'page' => 1];
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $type): array
    {
        $items = match ($type) {
            'post' => array_merge(
                [['id' => 11, 'status' => 'publish', 'title' => 'Bài mẫu <script>x</script>', 'url' => $this->baseUrl.'/bai-mau/', 'slug' => 'bai-mau', 'excerpt' => 'Mô tả', 'date_gmt' => '2026-10-01T00:00:00+00:00', 'modified_gmt' => '2026-10-02T00:00:00+00:00', 'term_ids' => [1, 5], 'managed' => false]],
                array_values(array_map(fn ($p) => ['id' => $p['id'], 'status' => $p['status'], 'title' => $p['title'], 'url' => $this->baseUrl.'/?p='.$p['id'], 'slug' => '', 'excerpt' => '', 'date_gmt' => null, 'modified_gmt' => null, 'term_ids' => [], 'managed' => true], $this->posts)),
            ),
            'page' => [['id' => 12, 'status' => 'publish', 'title' => 'Giới thiệu', 'url' => 'https://evil.example.net/x', 'slug' => 'gioi-thieu', 'excerpt' => '', 'date_gmt' => null, 'modified_gmt' => null, 'term_ids' => [], 'managed' => false]],
            'product' => [['id' => 13, 'status' => 'publish', 'title' => 'Áo thun', 'url' => $this->baseUrl.'/ao-thun/', 'slug' => 'ao-thun', 'excerpt' => '', 'date_gmt' => null, 'modified_gmt' => null, 'term_ids' => [9], 'managed' => false, 'product' => ['sku' => 'SX-1', 'price' => '199000', 'regular_price' => '249000', 'sale_price' => '199000', 'stock_status' => 'instock', 'currency' => 'VND', 'type' => 'simple']]],
            default => [],
        };

        return ['items' => $items, 'total' => count($items), 'total_pages' => 1, 'page' => 1];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function upload(Request $request, array $query): array
    {
        foreach ($this->media as $m) {
            if ($m['key'] === $query['idempotency_key']) {
                return [200, ['id' => $m['id'], 'created' => false, 'url' => $m['url']]];
            }
        }
        $id = $this->nextId++;
        $this->media[$id] = ['id' => $id, 'key' => $query['idempotency_key'], 'url' => $this->baseUrl.'/wp-content/uploads/'.$query['filename'], 'alt' => $query['alt'] ?? '', 'bytes' => strlen($request->body())];

        return [201, ['id' => $id, 'created' => true, 'url' => $this->media[$id]['url']]];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function upsert(array $json, ?int $id): array
    {
        $existing = null;
        foreach ($this->posts as $p) {
            if ($p['key'] === $json['idempotency_key']) {
                $existing = $p['id'];
            }
        }
        if ($id !== null && ! isset($this->posts[$id])) {
            return [404, ['code' => 'sitex_post_not_found', 'message' => 'Post not found.']];
        }
        $postId = $id ?? $existing ?? $this->nextId++;
        $created = ! isset($this->posts[$postId]);
        if (! $created) {
            if (($this->posts[$postId]['status'] ?? '') === 'trash') {
                return [410, ['code' => 'sitex_post_trashed', 'message' => 'Trashed']];
            }
            $conflicts = $this->conflicts($postId, is_array($json['expected'] ?? null) ? $json['expected'] : [], (bool) ($json['force'] ?? false));
            if ($conflicts !== []) {
                return [409, ['code' => 'sitex_conflict', 'message' => 'Changed in WordPress', 'data' => ['status' => 409, 'conflicts' => $conflicts]]];
            }
        }
        $slug = (string) ($json['slug'] ?? '') !== '' ? (string) $json['slug'] : ($this->posts[$postId]['slug'] ?? 'post-'.$postId);
        $this->posts[$postId] = [
            'id' => $postId, 'key' => $json['idempotency_key'], 'title' => $json['title'], 'content' => $json['content_html'],
            'status' => $json['status'], 'featured' => $json['featured_media_id'] ?? null, 'seo' => $json['seo'] ?? [],
            'tags' => $json['tags'] ?? [], 'writes' => ($this->posts[$postId]['writes'] ?? 0) + 1,
            'slug' => $slug, 'schema' => array_key_exists('schema', $json) ? $json['schema'] : ($this->posts[$postId]['schema'] ?? null),
            'date_gmt' => $json['date_gmt'] ?? null, 'modified' => gmdate('c', time() + ($this->posts[$postId]['writes'] ?? 0)),
            'revision' => ($this->posts[$postId]['revision'] ?? 1000) + 1,
        ];
        $this->posts[$postId]['written_hash'] = hash('sha256', (string) $json['content_html']);

        return [$created ? 201 : 200, ['id' => $postId, 'created' => $created, 'status' => $json['status'], 'url' => $this->baseUrl.'/?p='.$postId,
            'seo_adapter' => $this->seoAdapter, 'state' => $this->state($postId)]];
    }

    /**
     * Mirrors PostService::conflicts() of the plugin.
     *
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    private function conflicts(int $id, array $expected, bool $force): array
    {
        $p = $this->posts[$id];
        $current = hash('sha256', (string) $p['content']);
        $out = [];
        if (! $force) {
            if (isset($p['written_hash']) && $p['written_hash'] !== $current) {
                $out[] = 'manual_edit';
            }
            if (($expected['slug'] ?? '') !== '' && $expected['slug'] !== $p['slug']) {
                $out[] = 'slug_changed';
            }
            if (($expected['seo_hash'] ?? '') !== '' && $expected['seo_hash'] !== $this->seoHash($id)) {
                $out[] = 'seo_modified';
            }
        }
        if (($expected['content_hash'] ?? '') !== '' && $expected['content_hash'] !== $current) {
            $out[] = 'manual_edit';
        } elseif ($force && ($expected['content_hash'] ?? '') === '') {
            $out[] = 'expected_state_required';
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string, mixed>
     */
    public function state(int $id): array
    {
        $p = $this->posts[$id];
        $seo = is_array($p['seo'] ?? null) ? $p['seo'] : [];

        return [
            'id' => $id, 'post_type' => 'post', 'status' => $p['status'], 'title' => $p['title'], 'slug' => $p['slug'] ?? 'post-'.$id,
            'url' => $this->baseUrl.'/'.($p['slug'] ?? 'post-'.$id).'/', 'modified_gmt' => $p['modified'] ?? null,
            'content_hash' => hash('sha256', (string) $p['content']), 'written_hash' => $p['written_hash'] ?? '', 'managed' => true,
            'idempotency_key' => $p['key'], 'revision_id' => $p['revision'] ?? null, 'featured_media_id' => (int) ($p['featured'] ?? 0),
            'seo' => ['title' => (string) ($seo['title'] ?? ''), 'description' => (string) ($seo['description'] ?? ''), 'focus_keyword' => (string) ($seo['focus_keyword'] ?? ''),
                'canonical' => (string) ($seo['canonical'] ?? ''), 'noindex' => (bool) ($seo['noindex'] ?? false)],
            'seo_hash' => $this->seoHash($id), 'seo_adapter' => $this->seoAdapter,
        ];
    }

    public function seoHash(int $id): string
    {
        $seo = is_array($this->posts[$id]['seo'] ?? null) ? $this->posts[$id]['seo'] : [];

        return hash('sha256', (string) json_encode([(string) ($seo['title'] ?? ''), (string) ($seo['description'] ?? ''), (string) ($seo['focus_keyword'] ?? ''),
            (string) ($seo['canonical'] ?? ''), (bool) ($seo['noindex'] ?? false)]));
    }

    /**
     * Simulates a human editing the post in wp-admin.
     *
     * @param  array<string, mixed>  $changes  content | slug | seo | status
     */
    public function editInWordPress(int $id, array $changes): void
    {
        foreach ($changes as $k => $v) {
            $this->posts[$id][$k] = $v;
        }
        $this->posts[$id]['modified'] = gmdate('c', time() + 3600);
        $this->posts[$id]['revision'] = ($this->posts[$id]['revision'] ?? 1000) + 1;
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function lookup(string $key): array
    {
        foreach ($this->posts as $p) {
            if ($p['key'] === $key) {
                return [200, $this->state((int) $p['id'])];
            }
        }

        return [404, ['code' => 'sitex_post_not_found', 'message' => 'Post not found.']];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function rotate(array $json, string $currentKey): array
    {
        $this->keys[(string) $json['key_id']] = (string) $json['secret'];

        return [200, ['rotated' => true, 'key_id' => $json['key_id']]];
    }
}
