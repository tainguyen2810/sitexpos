<?php

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Providers\FakeProvider;
use SiteXPost\Modules\Media\Actions\AutoIllustrate;
use SiteXPost\Modules\Media\Domain\ImageGenerationJob;
use SiteXPost\Modules\Media\Domain\ImagePlacement;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Media\Domain\MediaAutofill;
use SiteXPost\Modules\Media\Jobs\AutoIllustrateArticle;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Events\ArticleDraftReady;
use SiteXPost\Modules\Writer\Workflows\DraftWorkflow;
use Tests\Support\FakeImageProvider;

require_once __DIR__.'/helpers.php';

/*
 * Auto images after a draft (STYLE-AND-AUTO-IMAGES.md §2): library first,
 * AI for the rest, at least N images, labelled, budget-checked, idempotent,
 * and never touching what the user chose.
 */

beforeEach(function () {
    Storage::fake(config('sitex.media.disk'));
    seedAi();
    config(['sitex.ai.fake_provider' => true]);
    app()->instance(FakeProvider::class, new FakeImageProvider);
});

function autoRun(Workspace $ws, string $article, int $target = 2, ?string $version = 'v1'): ?MediaAutofill
{
    return app(TenantContext::class)->runAs($ws, null, fn () => app(AutoIllustrate::class)->run($ws->id, $article, $version, $target));
}

/** @return list<ImagePlacement> */
function acceptedPlacements(Workspace $ws, string $article): array
{
    return app(TenantContext::class)->runAs($ws, null, fn () => ImagePlacement::query()->where('article_id', $article)->where('status', 'accepted')
        ->whereNotNull('media_asset_id')->orderBy('position')->get()->all());
}

function libraryAsset(Workspace $ws, string $siteId, array $attrs): string
{
    return app(TenantContext::class)->runAs($ws, null, function () use ($siteId, $attrs): string {
        $a = new MediaAsset;
        $a->forceFill($attrs + ['site_id' => $siteId, 'source_type' => 'wordpress', 'status' => 'ready', 'license' => 'wordpress_site', 'mime' => 'image/jpeg',
            'width' => 1200, 'height' => 800])->save();

        return $a->id;
    });
}

it('fills at least two images with AI when the library has nothing, labelled and in their slots', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site'], ['status' => 'NEEDS_HUMAN_REVIEW']);

    $row = autoRun($t['workspace'], $article);

    expect($row->status)->toBe('completed')->and($row->filled)->toBeGreaterThanOrEqual(2)->and($row->ai_count)->toBeGreaterThanOrEqual(2)->and($row->library_count)->toBe(0);
    $placed = acceptedPlacements($t['workspace'], $article);
    expect(collect($placed)->pluck('slot')->all())->toContain('featured')
        ->and(collect($placed)->every(fn (ImagePlacement $p) => trim((string) $p->alt_text) !== ''))->toBeTrue();
    $assets = app(TenantContext::class)->runAs($t['workspace'], null, fn () => MediaAsset::query()->whereIn('id', collect($placed)->pluck('media_asset_id'))->get());
    expect($assets->every(fn (MediaAsset $a) => $a->is_ai_generated && $a->license === 'ai_generated'))->toBeTrue()
        ->and(collect($placed)->pluck('caption')->unique()->all())->toBe([__('media.labels.ai_caption')]);

    // The media editor shows the state.
    $view = $this->actingAs($t['editor'])->getJson(p6url($t['workspace'], "media/articles/{$article}/placements"))->assertOk()->json('data');
    expect($view['autofill']['status'])->toBe('completed')->and($view['autofill']['target'])->toBe(2);
});

it('uses matching library images first and only generates what is missing', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site'], ['status' => 'NEEDS_HUMAN_REVIEW']);
    $own = libraryAsset($t['workspace'], $t['site']->id, ['title' => 'Cách chọn máy lọc nước cho gia đình', 'alt_text' => 'Máy lọc nước gia đình']);
    // An AI image made for ANOTHER article is never reused automatically.
    libraryAsset($t['workspace'], $t['site']->id, ['title' => 'Tiêu chí chọn máy lọc nước công suất lọc lõi lọc', 'source_type' => 'ai', 'license' => 'ai_generated',
        'is_ai_generated' => true, 'metadata' => ['article_id' => '01jz0000000000000000000000']]);

    $row = autoRun($t['workspace'], $article);

    $placed = acceptedPlacements($t['workspace'], $article);
    expect($row->status)->toBe('completed')->and($row->library_count)->toBe(1)->and($row->ai_count)->toBeGreaterThanOrEqual(1)
        ->and(collect($placed)->firstWhere('slot', 'featured')?->media_asset_id)->toBe($own)
        ->and(app(TenantContext::class)->runAs($t['workspace'], null, fn () => ImageGenerationJob::query()->where('article_id', $article)->count()))->toBe($row->ai_count);
});

it('marks the article incomplete (and never blocks it) when no image model is available', function () {
    config(['sitex.ai.fake_provider' => false]);
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site'], ['status' => 'NEEDS_HUMAN_REVIEW']);

    $row = autoRun($t['workspace'], $article);

    expect($row->status)->toBe('incomplete')->and($row->error_code)->toBe('no_image_model')->and($row->filled)->toBe(0)
        ->and(app(TenantContext::class)->runAs($t['workspace'], null, fn () => Article::query()->find($article)->status->value))->toBe('NEEDS_HUMAN_REVIEW');
});

it('is idempotent for the same draft and never changes what the user chose', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site'], ['status' => 'NEEDS_HUMAN_REVIEW']);
    $mine = libraryAsset($t['workspace'], $t['site']->id, ['title' => 'Ảnh tôi chọn', 'alt_text' => 'Ảnh tôi chọn']);
    $this->actingAs($t['editor'])->putJson(p6url($t['workspace'], "media/articles/{$article}/placements"), ['items' => [
        ['slot' => 'featured', 'media_asset_id' => $mine, 'alt_text' => 'Ảnh tôi chọn', 'status' => 'accepted'],
    ]])->assertOk();

    autoRun($t['workspace'], $article);
    $jobs = app(TenantContext::class)->runAs($t['workspace'], null, fn () => ImageGenerationJob::query()->count());
    autoRun($t['workspace'], $article); // replayed event, same version

    $featured = collect(acceptedPlacements($t['workspace'], $article))->where('slot', 'featured');
    expect($featured)->toHaveCount(1)->and($featured->first()->media_asset_id)->toBe($mine)->and($featured->first()->origin)->toBe('user')
        ->and(app(TenantContext::class)->runAs($t['workspace'], null, fn () => ImageGenerationJob::query()->count()))->toBe($jobs)
        ->and(count(acceptedPlacements($t['workspace'], $article)))->toBeGreaterThanOrEqual(2);
});

it('queues auto images when a draft is ready, following the preset choice', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site'], ['status' => 'NEEDS_HUMAN_REVIEW']);
    Queue::fake();
    app(TenantContext::class)->runAs($t['workspace'], null, fn () => ArticleDraftReady::dispatch($t['workspace']->id, $article, null, 3));
    Queue::assertPushed(AutoIllustrateArticle::class, fn (AutoIllustrateArticle $j) => $j->articleId === $article && $j->target === 3);

    [$off, $four, $none] = app(TenantContext::class)->runAs($t['workspace'], null, function () use ($article) {
        $p = new WritingPreset;
        $p->forceFill(['name' => 'Tắt ảnh', 'defaults' => ['auto_images' => false]])->save();
        $q = new WritingPreset;
        $q->forceFill(['name' => '4 ảnh', 'defaults' => ['min_images' => 4]])->save();
        $a = Article::query()->find($article);

        return [DraftWorkflow::imageTarget($a->forceFill(['writing_preset_id' => $p->id])), DraftWorkflow::imageTarget($a->forceFill(['writing_preset_id' => $q->id])),
            DraftWorkflow::imageTarget($a->forceFill(['writing_preset_id' => null]))];
    });
    expect([$off, $four, $none])->toBe([0, 4, 2]);

    app(TenantContext::class)->runAs($t['workspace'], null, fn () => ArticleDraftReady::dispatch($t['workspace']->id, $article, null, 0));
    Queue::assertPushed(AutoIllustrateArticle::class, 1);
});
