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
            $route === '/sitex-post/v1/media' => $this->upload($request, $query),
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
            return [403, ['code' => 'sitex_not_managed', 'message' => 'Not managed']];
        }
        $postId = $id ?? $existing ?? $this->nextId++;
        $created = ! isset($this->posts[$postId]);
        $this->posts[$postId] = [
            'id' => $postId, 'key' => $json['idempotency_key'], 'title' => $json['title'], 'content' => $json['content_html'],
            'status' => $json['status'], 'featured' => $json['featured_media_id'] ?? null, 'seo' => $json['seo'] ?? [],
            'tags' => $json['tags'] ?? [], 'writes' => ($this->posts[$postId]['writes'] ?? 0) + 1,
        ];

        return [$created ? 201 : 200, ['id' => $postId, 'created' => $created, 'status' => $json['status'], 'url' => $this->baseUrl.'/?p='.$postId, 'seo_adapter' => 'yoast']];
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
