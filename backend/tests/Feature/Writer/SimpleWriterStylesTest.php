<?php

use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Sites\Domain\SiteContentItem;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Pipeline\ArticleContext;
use SiteXPost\Modules\Writer\Pipeline\StyleLearner;
use SiteXPost\Modules\Writer\Support\BuiltinStyles;
use SiteXPost\Modules\Writer\Workflows\DraftWorkflow;

require_once __DIR__.'/helpers.php';
use SiteXPost\Modules\AiGateway\Providers\FakeProvider;
use Tests\Support\ScriptedAi;

/*
 * v1.0.9 simple writer: ready-made styles, learning from one sample and
 * recording where the samples came from, the per-article image switch,
 * and the author / category data behind "learn from an author / a category".
 */

function simpleSample(string $topic): string
{
    return 'Chào bạn, hôm nay mình cùng tìm hiểu '.$topic.'. '
        .str_repeat('Mình sẽ đi từng ý ngắn gọn, dễ hiểu, có ví dụ thực tế để bạn áp dụng ngay tại nhà mà không cần kiến thức chuyên sâu. ', 5);
}

function simpleStyleAi(): ScriptedAi
{
    $ai = new ScriptedAi;
    $ai->on('style_profile', fn () => BuiltinStyles::profile('friendly') + ['vocabulary' => ['bạn'], 'signature_phrases' => ['thử ngay nhé'], 'avoid' => ['thuật ngữ khó']]);
    app()->instance(FakeProvider::class, $ai);

    return $ai;
}

function voiceOf(array $t, string $articleId): ?array
{
    return app(TenantContext::class)->runAs($t['workspace'], null, fn () => app(ArticleContext::class)->brandVoice(Article::query()->findOrFail($articleId)));
}

it('lists the ready-made styles and writes with one when chosen', function () {
    $t = writerTenant();
    expect($this->actingAs($t['owner'])->getJson(writerUrl($t, 'options'))->assertOk()->json('data.builtin_styles'))->toBe(BuiltinStyles::KEYS);

    createArticle($t, ['builtin_style' => 'nope', 'start' => false])->assertStatus(422)->assertJsonPath('error.details.builtin_style.0', fn ($m) => is_string($m));

    $id = createArticle($t, ['builtin_style' => 'step_by_step', 'start' => false])->assertCreated()->json('data.id');
    $voice = voiceOf($t, $id);
    expect($voice['style_profile']['summary'])->toBe(BuiltinStyles::profile('step_by_step')['summary'])
        ->and($voice['style_profile'])->not->toHaveKey('unknown');

    // Every ready-made style is a valid, complete style profile.
    foreach (BuiltinStyles::KEYS as $k) {
        expect(StyleLearner::isUsable(BuiltinStyles::profile($k)))->toBeTrue();
    }
});

it('keeps a preset\'s learned style over a ready-made one', function () {
    $t = writerTenant();
    $preset = $this->actingAs($t['owner'])->postJson(writerUrl($t, 'presets'), ['name' => 'Giọng riêng', 'style_profile' => ['summary' => 'Giọng riêng của shop', 'tone' => 'Vui']])
        ->assertCreated()->json('data.id');
    $id = createArticle($t, ['writing_preset_id' => $preset, 'builtin_style' => 'formal', 'start' => false])->assertCreated()->json('data.id');

    expect(voiceOf($t, $id)['style_profile']['summary'])->toBe('Giọng riêng của shop');
});

it('makes an editable copy of a ready-made style', function () {
    $t = writerTenant();
    $this->actingAs($t['owner'])->postJson(writerUrl($t, 'presets'), ['name' => 'X', 'from_builtin' => 'bogus'])->assertStatus(422);
    $this->actingAs($t['viewer'])->postJson(writerUrl($t, 'presets'), ['name' => 'Bán hàng (bản riêng)', 'from_builtin' => 'sales'])->assertForbidden();

    $p = $this->actingAs($t['owner'])->postJson(writerUrl($t, 'presets'), ['name' => 'Bán hàng (bản riêng)', 'from_builtin' => 'sales'])->assertCreated()->json('data');
    expect($p['style_profile']['summary'])->toBe(BuiltinStyles::profile('sales')['summary'])
        ->and($p['style_sources']['origin'])->toBe(['type' => 'builtin', 'label' => 'sales'])
        ->and($p['style_learned_at'])->not->toBeNull();
});

it('learns from a single sample and remembers where the samples came from', function () {
    $t = researchTenant('One Sample');
    $ai = simpleStyleAi();
    $preset = $this->actingAs($t['owner'])->postJson(writerUrl($t, 'presets'), ['name' => 'Một bài'])->assertCreated()->json('data.id');
    $url = writerUrl($t, "presets/{$preset}/learn-style");

    $this->actingAs($t['owner'])->postJson($url, ['samples' => [['text' => simpleSample('a')]], 'origin' => ['type' => 'satellite']])->assertStatus(422);

    $res = $this->actingAs($t['owner'])->postJson($url, ['samples' => [['text' => simpleSample('pha cà phê')]], 'origin' => ['type' => 'paste', 'label' => '  Bài <b>mẫu</b>  ']])->assertStatus(202);
    $run = app(TenantContext::class)->runAs($t['workspace'], null, fn () => JobRun::query()->find($res->json('data.job_run.id')));
    expect($run->status)->toBe('succeeded')->and($ai->count('style_profile'))->toBe(1);

    $p = $this->actingAs($t['owner'])->getJson(writerUrl($t, "presets/{$preset}"))->json('data');
    expect($p['style_sources']['used'])->toHaveCount(1)
        ->and($p['style_sources']['origin'])->toBe(['type' => 'paste', 'label' => 'Bài mẫu'])
        ->and($p['style_draft'])->not->toBeNull();
});

it('lets the article switch automatic images on or off over the preset', function () {
    $t = writerTenant();
    $preset = $this->actingAs($t['owner'])->postJson(writerUrl($t, 'presets'), ['name' => 'Không ảnh', 'defaults' => ['auto_images' => false, 'min_images' => 3]])->assertCreated()->json('data.id');

    $on = createArticle($t, ['writing_preset_id' => $preset, 'auto_images' => true, 'start' => false])->assertCreated()->json('data.id');
    $off = createArticle($t, ['auto_images' => false, 'start' => false, 'topic' => 'Intel AX200 có hỗ trợ Bluetooth 5.2 không?', 'primary_keyword' => 'intel ax200 bluetooth'])->assertCreated()->json('data.id');
    $inherit = createArticle($t, ['writing_preset_id' => $preset, 'start' => false, 'topic' => 'Cách cài driver Intel AX210 trên Windows', 'primary_keyword' => 'driver intel ax210'])->assertCreated()->json('data.id');

    $target = fn (string $id) => app(TenantContext::class)->runAs($t['workspace'], null, fn () => DraftWorkflow::imageTarget(Article::query()->findOrFail($id)));
    expect($target($on))->toBe(3)->and($target($off))->toBe(0)->and($target($inherit))->toBe(0);
});

it('lists a site\'s post authors for every member role, from the synced index', function () {
    $t = researchTenant('Authors Blog');
    app(TenantContext::class)->runAs($t['workspace'], null, function () use ($t) {
        $ids = SiteContentItem::query()->where('site_id', $t['site']->id)->where('post_type', 'post')->orderBy('wp_post_id')->pluck('id')->all();
        foreach ($ids as $i => $id) {
            SiteContentItem::query()->whereKey($id)->update($i < 4 ? ['author_wp_id' => 7, 'author_name' => 'Lan'] : ['author_wp_id' => 9, 'author_name' => 'Minh']);
        }
    });
    $url = "/api/v1/workspaces/{$t['workspace']->id}/sites/{$t['site']->id}/authors";

    foreach (['owner', 'editor', 'viewer'] as $role) {
        expect($this->actingAs($t[$role])->getJson($url)->assertOk()->json('data'))
            ->toBe([['wp_author_id' => 7, 'name' => 'Lan', 'posts' => 4], ['wp_author_id' => 9, 'name' => 'Minh', 'posts' => 2]]);
    }
    expect($this->actingAs($t['viewer'])->getJson("/api/v1/workspaces/{$t['workspace']->id}/sites/{$t['site']->id}/content?filter[author]=9")->json('data'))->toHaveCount(2)
        ->and($this->actingAs($t['viewer'])->getJson("/api/v1/workspaces/{$t['workspace']->id}/sites/{$t['site']->id}/content?filter[term]=1&filter[post_type]=post")->json('data'))->toHaveCount(6);

    // Another workspace's member cannot see them.
    $other = workspaceWithRoles('Other Co');
    $this->actingAs($other['owner'])->getJson($url)->assertNotFound();
});

it('does not save a preset name collision when copying twice', function () {
    $t = writerTenant();
    $this->actingAs($t['owner'])->postJson(writerUrl($t, 'presets'), ['name' => 'Tin tức (bản riêng)', 'from_builtin' => 'news'])->assertCreated();
    $this->actingAs($t['owner'])->postJson(writerUrl($t, 'presets'), ['name' => 'Tin tức (bản riêng)', 'from_builtin' => 'news'])->assertStatus(422);
    expect(app(TenantContext::class)->runAs($t['workspace'], null, fn () => WritingPreset::query()->where('name', 'Tin tức (bản riêng)')->count()))->toBe(1);
});
