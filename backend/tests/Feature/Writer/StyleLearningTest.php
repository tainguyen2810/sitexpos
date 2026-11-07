<?php

use Illuminate\Support\Str;
use SiteXPost\Kernel\Support\JobRun;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\AiGateway\Providers\FakeProvider;
use SiteXPost\Modules\Sites\Domain\SiteContentItem;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Pipeline\ArticleContext;
use SiteXPost\Modules\Writer\Pipeline\StyleLearner;
use Tests\Support\ScriptedAi;

require_once __DIR__.'/helpers.php';

/*
 * Learn writing style (STYLE-AND-AUTO-IMAGES.md §1): own samples only,
 * one queued AI call, a reviewable draft, never copied sentences, applied
 * to the writer only after the user saves it.
 */

const STYLE_SENTENCE = 'Bạn chỉ cần nhớ ba bước đơn giản là chọn đúng nhu cầu so sánh giá và kiểm tra bảo hành trước khi mua';

function styleSample(string $topic): string
{
    return 'Chào bạn, hôm nay mình cùng tìm hiểu '.$topic.'. '.STYLE_SENTENCE.'. '
        .str_repeat('Mình sẽ đi từng ý ngắn gọn, dễ hiểu, có ví dụ thực tế để bạn áp dụng ngay tại nhà mà không cần kiến thức chuyên sâu. ', 5)
        .'Tóm lại, nếu còn thắc mắc bạn cứ để lại bình luận nhé.';
}

function styleAi(): ScriptedAi
{
    $ai = new ScriptedAi;
    $ai->on('style_profile', fn () => [
        'summary' => 'Văn phong thân thiện, gần gũi như trò chuyện. '.STYLE_SENTENCE.'.',
        'tone' => 'Thân thiện, tích cực', 'addressing' => 'Xưng "mình", gọi người đọc là "bạn"', 'sentence_style' => 'Câu ngắn, rõ ý',
        'paragraph_style' => 'Đoạn 2–3 câu', 'intro_pattern' => 'Mở bằng lời chào và nêu chủ đề', 'conclusion_pattern' => 'Tóm lại và mời bình luận',
        'heading_style' => 'Tiêu đề dạng câu hỏi', 'formatting' => 'Danh sách gạch đầu dòng cho các bước',
        'vocabulary' => ['mình', 'bạn', 'tóm lại', 'mình', ''], 'signature_phrases' => ['bạn cứ để lại bình luận nhé', STYLE_SENTENCE],
        'avoid' => ['thuật ngữ khó hiểu'], 'dos' => ['Dùng ví dụ thực tế'], 'donts' => ['Không viết câu dài'],
    ]);
    app()->instance(FakeProvider::class, $ai);

    return $ai;
}

function stylePreset(array $t, array $extra = []): string
{
    return test()->actingAs($t['owner'])->postJson(writerUrl($t, 'presets'), ['name' => 'Giọng blog '.Str::random(4)] + $extra)->assertCreated()->json('data.id');
}

it('learns a style from pasted samples as a draft, without copied sentences or stored sample text', function () {
    $t = researchTenant('Style Shop');
    $ai = styleAi();
    $preset = stylePreset($t);

    $res = $this->actingAs($t['owner'])->postJson(writerUrl($t, "presets/{$preset}/learn-style"), ['samples' => [
        ['title' => 'Bài 1', 'text' => styleSample('cách chọn nồi chiên')], ['text' => styleSample('cách vệ sinh máy hút bụi')],
    ]])->assertStatus(202);
    $run = app(TenantContext::class)->runAs($t['workspace'], null, fn () => JobRun::query()->find($res->json('data.job_run.id')));
    expect($run->status)->toBe('succeeded')->and($ai->count('style_profile'))->toBe(1);

    $p = $this->actingAs($t['owner'])->getJson(writerUrl($t, "presets/{$preset}"))->assertOk()->json('data');
    expect($p['style_profile'])->toBeNull()
        ->and($p['style_draft']['tone'])->toBe('Thân thiện, tích cực')
        ->and($p['style_draft']['summary'])->toBe('Văn phong thân thiện, gần gũi như trò chuyện.')
        ->and($p['style_draft']['signature_phrases'])->toBe(['bạn cứ để lại bình luận nhé'])
        ->and($p['style_draft']['vocabulary'])->toBe(['mình', 'bạn', 'tóm lại'])
        ->and($p['style_sources']['used'])->toHaveCount(2)
        ->and(json_encode($p))->not->toContain('nồi chiên. Bạn chỉ cần');

    // Not applied yet: the writer does not see the draft.
    $voice = app(TenantContext::class)->runAs($t['workspace'], null, function () use ($t, $preset) {
        $a = new Article;
        $a->forceFill(['site_id' => $t['site']->id, 'writing_preset_id' => $preset]);

        return app(ArticleContext::class)->brandVoice($a);
    });
    expect($voice['style_profile'])->toBeNull();

    // The user reviews, edits and saves → applied, draft closed.
    $draft = collect($p['style_draft'])->only([...StyleLearner::TEXT_FIELDS, ...StyleLearner::LIST_FIELDS])->all();
    $saved = $this->actingAs($t['owner'])->patchJson(writerUrl($t, "presets/{$preset}"), ['style_profile' => ['tone' => 'Thân thiện, vui vẻ'] + $draft])->assertOk()->json('data');
    expect($saved['style_profile']['tone'])->toBe('Thân thiện, vui vẻ')->and($saved['style_draft'])->toBeNull()->and($saved['style_learned_at'])->not->toBeNull();

    $voice = app(TenantContext::class)->runAs($t['workspace'], null, function () use ($t, $preset) {
        $a = new Article;
        $a->forceFill(['site_id' => $t['site']->id, 'writing_preset_id' => $preset]);

        return app(ArticleContext::class)->brandVoice($a);
    });
    expect($voice['style_profile']['addressing'])->toContain('"bạn"');
});

it('learns from the site\'s own published posts and skips pages it cannot read', function () {
    $host = 'https://own-blog.example.vn';
    $page = fn (string $title, string $body) => '<!doctype html><html><head><title>'.$title.'</title></head><body><article><h1>'.$title.'</h1><p>'.$body.'</p></article></body></html>';
    $t = researchTenant('Own Blog', ['pages' => [
        $host.'/cach-chon-laptop-cho-sinh-vien/' => $page('Laptop', styleSample('laptop cho sinh viên')),
        $host.'/top-5-tai-nghe-chong-on/' => $page('Tai nghe', styleSample('tai nghe chống ồn')),
    ]]);
    $ai = styleAi();
    $preset = stylePreset($t, ['site_id' => $t['site']->id]);
    $items = app(TenantContext::class)->runAs($t['workspace'], null, fn () => SiteContentItem::query()->where('site_id', $t['site']->id)->where('post_type', 'post')
        ->whereIn('slug', ['cach-chon-laptop-cho-sinh-vien', 'top-5-tai-nghe-chong-on', 'huong-dan-ve-sinh-ban-phim-co'])->pluck('id')->all());

    $this->actingAs($t['owner'])->postJson(writerUrl($t, "presets/{$preset}/learn-style"), ['content_item_ids' => $items])->assertStatus(202);

    $p = $this->actingAs($t['owner'])->getJson(writerUrl($t, "presets/{$preset}"))->json('data');
    expect($p['style_sources']['used'])->toHaveCount(2)
        ->and(collect($p['style_sources']['used'])->pluck('type')->unique()->all())->toBe(['site_post'])
        ->and(collect($p['style_sources']['skipped'])->pluck('code')->all())->toBe(['no_readable_text'])
        ->and($ai->count('style_profile'))->toBe(1);
});

it('fails clearly when no sample can be read, without calling the AI', function () {
    $t = researchTenant('Few Samples');
    $ai = styleAi();
    $preset = stylePreset($t, ['site_id' => $t['site']->id]);
    $item = app(TenantContext::class)->runAs($t['workspace'], null, fn () => SiteContentItem::query()->where('post_type', 'post')->value('id'));

    $res = $this->actingAs($t['owner'])->postJson(writerUrl($t, "presets/{$preset}/learn-style"), ['content_item_ids' => [$item]])->assertStatus(202);
    $run = app(TenantContext::class)->runAs($t['workspace'], null, fn () => JobRun::query()->find($res->json('data.job_run.id')));
    expect($run->status)->toBe('failed')->and($run->error_code)->toBe('not_enough_samples')->and($ai->count('style_profile'))->toBe(0);
});

it('validates samples, roles and discards a pending draft', function () {
    $t = researchTenant('Style Rules');
    styleAi();
    $preset = stylePreset($t);
    $url = writerUrl($t, "presets/{$preset}/learn-style");

    $this->actingAs($t['owner'])->postJson($url, ['samples' => []])->assertStatus(422);
    $this->actingAs($t['owner'])->postJson($url, ['samples' => [['text' => 'quá ngắn'], ['text' => styleSample('b')]]])->assertStatus(422);
    $this->actingAs($t['owner'])->postJson($url, ['content_item_ids' => ['01jz0000000000000000000000', '01jz0000000000000000000001']])->assertStatus(422); // no site on the preset
    $this->actingAs($t['viewer'])->postJson($url, ['samples' => [['text' => styleSample('a')], ['text' => styleSample('b')]]])->assertForbidden();
    $this->actingAs($t['owner'])->patchJson(writerUrl($t, "presets/{$preset}"), ['style_draft' => ['tone' => 'x']])->assertStatus(422);

    $this->actingAs($t['owner'])->postJson($url, ['samples' => [['text' => styleSample('a')], ['text' => styleSample('b')]]])->assertStatus(202);
    $this->actingAs($t['viewer'])->deleteJson(writerUrl($t, "presets/{$preset}/style-draft"))->assertForbidden();
    $p = $this->actingAs($t['owner'])->deleteJson(writerUrl($t, "presets/{$preset}/style-draft"))->assertOk()->json('data');
    expect($p['style_draft'])->toBeNull();
    expect(app(TenantContext::class)->runAs($t['workspace'], null, fn () => WritingPreset::query()->find($preset)->style_profile))->toBeNull();
});

it('strips copied runs of words and caps every field', function () {
    $out = StyleLearner::sanitize([
        'summary' => 'Ngắn gọn. '.STYLE_SENTENCE.'.', 'tone' => str_repeat('a', 2000),
        'vocabulary' => array_fill(0, 30, 'x'), 'dos' => [STYLE_SENTENCE, 'Dùng ví dụ'], 'unknown' => 'bỏ qua',
    ], [styleSample('z')]);
    expect($out['summary'])->toBe('Ngắn gọn.')->and(mb_strlen($out['tone']))->toBeLessThanOrEqual(600)
        ->and($out['vocabulary'])->toBe(['x'])->and($out['dos'])->toBe(['Dùng ví dụ'])->and($out)->not->toHaveKey('unknown');
});
