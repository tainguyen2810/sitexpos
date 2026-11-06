<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Providers\FakeProvider;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Domain\MediaVersion;
use Tests\Support\FakeImageProvider;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Storage::fake(config('sitex.media.disk'));
    seedAi();
});

function pngUpload(string $name = 'anh.png', string $extra = ''): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'png');
    file_put_contents($path, FakeImageProvider::png(800, 600).$extra);

    return new UploadedFile($path, $name, null, null, true);
}

it('uploads, verifies and optimizes an image in the queue (webp + thumbnail)', function () {
    $t = publishingTenant();
    $res = $this->actingAs($t['editor'])->post(p6url($t['workspace'], 'media/assets'), ['file' => pngUpload(), 'license' => 'owned', 'alt_text' => 'Máy lọc nước'], ['Accept' => 'application/json'])
        ->assertStatus(202);
    $id = $res->json('data.id');
    $asset = app(TenantContext::class)->runAs($t['workspace'], null, fn () => MediaAsset::query()->find($id));
    expect($asset->status)->toBe('ready')->and($asset->mime)->toBe('image/png')->and($asset->width)->toBe(800);
    $kinds = app(TenantContext::class)->runAs($t['workspace'], null, fn () => MediaVersion::query()->where('media_asset_id', $id)->pluck('kind')->all());
    expect($kinds)->toContain('original', 'optimized', 'webp', 'thumbnail');

    $this->actingAs($t['viewer'])->post(p6url($t['workspace'], 'media/assets'), ['file' => pngUpload(), 'license' => 'owned'], ['Accept' => 'application/json'])->assertForbidden();
});

it('rejects disguised executables, polyglots and extension mismatches', function () {
    $t = publishingTenant();
    $php = tempnam(sys_get_temp_dir(), 'php');
    file_put_contents($php, "<?php system(\$_GET['c']); ?>");
    $cases = [
        [new UploadedFile($php, 'shell.png', null, null, true), 'unsupported_type'],
        [pngUpload('anh.png', '<?php echo 1; ?>'), 'suspicious_content'],
        [pngUpload('anh.php.png'), 'suspicious_content'],
        [pngUpload('anh.jpg'), 'extension_mismatch'],
    ];
    foreach ($cases as [$file, $code]) {
        $this->actingAs($t['owner'])->post(p6url($t['workspace'], 'media/assets'), ['file' => $file, 'license' => 'owned'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('error.code', $code);
    }
    expect(app(TenantContext::class)->runAs($t['workspace'], null, fn () => MediaAsset::query()->count()))->toBe(0);
});

it('generates AI images through the AI Gateway, labels them and never lets them be product photos', function () {
    config(['sitex.ai.fake_provider' => true]);
    $provider = new FakeImageProvider;
    app()->instance(FakeProvider::class, $provider);
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);

    $preview = $this->actingAs($t['editor'])->postJson(p6url($t['workspace'], 'media/generations/preview'), ['article_id' => $article, 'image_type' => 'featured',
        'context' => 'Liên hệ admin@example.com hoặc https://evil.example/x để biết thêm'])->assertOk();
    expect($preview->json('data.prompt'))->not->toContain('admin@example.com')->not->toContain('evil.example');

    $res = $this->actingAs($t['editor'])->postJson(p6url($t['workspace'], 'media/generations'), ['article_id' => $article, 'image_type' => 'featured', 'image_count' => 1])
        ->assertStatus(202);
    $job = $this->actingAs($t['editor'])->getJson(p6url($t['workspace'], 'media/generations/'.$res->json('data.id')))->assertOk()->json('data');
    expect($job['status'])->toBe('succeeded')->and($job['assets'])->toHaveCount(1)->and($job['model'])->not->toBeNull();

    $assetId = $job['assets'][0]['id'];
    $asset = $this->actingAs($t['editor'])->getJson(p6url($t['workspace'], 'media/assets/'.$assetId))->json('data');
    expect($asset['is_ai_generated'])->toBeTrue()->and($asset['license'])->toBe('ai_generated')->and($asset['source_type'])->toBe('ai');

    $this->actingAs($t['editor'])->putJson(p6url($t['workspace'], 'media/articles/'.$article.'/placements'), ['items' => [
        ['slot' => 'product', 'media_asset_id' => $assetId, 'alt_text' => 'Sản phẩm', 'status' => 'accepted'],
    ]])->assertStatus(422)->assertJsonPath('error.code', 'ai_not_product_photo');
});

it('renders infographics only from sourced or customer-provided data, deterministically', function () {
    $t = publishingTenant();
    $spec = ['site_id' => $t['site']->id, 'type' => 'bar_chart', 'title' => 'Chi phí thay lõi', 'items' => [['label' => 'Lõi 1', 'value' => 120000], ['label' => 'Lõi 2', 'value' => 90000]]];
    $this->actingAs($t['editor'])->postJson(p6url($t['workspace'], 'media/infographics'), $spec)->assertStatus(422)->assertJsonPath('error.code', 'infographic_needs_source');
    $ok = $this->actingAs($t['editor'])->postJson(p6url($t['workspace'], 'media/infographics'), $spec + ['customer_provided' => true])->assertSuccessful();
    expect($ok->json('data.source_type'))->toBe('infographic');
});

it('serves media files only through signed, expiring URLs', function () {
    $t = publishingTenant();
    $id = $this->actingAs($t['editor'])->post(p6url($t['workspace'], 'media/assets'), ['file' => pngUpload(), 'license' => 'owned'], ['Accept' => 'application/json'])->json('data.id');
    $this->get('/api/v1/media-files/'.$t['workspace']->id.'/'.$id.'/webp')->assertForbidden();
    $signed = $this->actingAs($t['viewer'])->getJson(p6url($t['workspace'], 'media/assets/'.$id))->json('data.preview_url');
    auth()->forgetGuards();
    $file = $this->get($signed)->assertOk();
    expect($file->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('lists media with signed previews for processed files and a constant number of queries (no N+1)', function () {
    $t = publishingTenant();
    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($t['editor'])->post(p6url($t['workspace'], 'media/assets'), ['file' => pngUpload("anh{$i}.png", (string) $i), 'license' => 'owned'], ['Accept' => 'application/json'])->assertStatus(202);
    }
    app(TenantContext::class)->runAs($t['workspace'], null, fn () => (new MediaAsset)->forceFill([
        'source_type' => 'url', 'status' => 'pending', 'source_url' => 'https://example.com/a.jpg', 'license' => 'owned',
    ])->save());

    DB::enableQueryLog();
    $rows = $this->actingAs($t['editor'])->getJson(p6url($t['workspace'], 'media/assets'))->assertOk()->json('data');
    // the variant check is a subquery of the list query, never one query per asset
    $perAsset = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $q) => str_contains($q, 'media_versions') && ! str_contains($q, 'media_assets'))->count();

    $byStatus = collect($rows)->groupBy('status');
    expect($rows)->toHaveCount(4)
        ->and($byStatus['ready']->pluck('preview_url')->every(fn ($u) => str_contains((string) $u, 'signature=')))->toBeTrue()
        ->and($byStatus['pending']->first()['preview_url'])->toBe('https://example.com/a.jpg')
        ->and($perAsset)->toBe(0);
});
