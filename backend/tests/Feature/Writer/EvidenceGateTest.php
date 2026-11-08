<?php

use SiteXPost\Modules\AiGateway\Domain\AiModel;
use SiteXPost\Modules\AiGateway\Domain\AiTaskRoute;
use SiteXPost\Modules\AiGateway\Gateway\ModelSelector;
use SiteXPost\Modules\Writer\Ai\WriterAi;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleVersion;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\ResearchDossier;
use SiteXPost\Modules\Writer\Pipeline\ApprovalGate;
use SiteXPost\Modules\Writer\Support\PlaceholderDetector;
use SiteXPost\Modules\Writer\Support\WriterSettings;
use Tests\Support\ScriptedAi;

require_once __DIR__.'/helpers.php';

/*
 * v1.0.6: no hollow articles. Research without enough verified evidence
 * stops before the brief with a clear "what is missing" message; the user
 * adds sources and researches again. Placeholder text ("Cần xác minh"
 * cells, talk about missing sources) is rewritten once and can never be
 * approved.
 */

function discoveryCanSearch(bool $on): void
{
    $route = AiTaskRoute::query()->where('task_key', WriterAi::TASK_DISCOVERY)->firstOrFail();
    $ids = app(ModelSelector::class)->orderedIds($route);
    foreach (AiModel::query()->whereIn('id', $ids)->get() as $m) {
        $m->forceFill(['capabilities' => array_merge($m->capabilities ?? [], ['web_search' => $on])])->save();
    }
}

it('stops research with a clear shortfall instead of writing an empty article', function () {
    $t = writerTenant();
    discoveryCanSearch(false);

    $id = createArticle($t, ['review_mode' => 'final_only', 'source_urls' => [], 'ai_source_discovery' => true, 'allow_web_search' => true])->assertCreated()->json('data.id');
    $article = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}"))->assertOk()->json('data');

    expect($article['status'])->toBe('RESEARCH_COMPLETE')
        ->and($article['blocked_reason'])->toBe('insufficient_evidence')
        ->and($article['last_error']['code'])->toBe('insufficient_evidence')
        ->and($article['last_error']['message'])->toContain('Chưa đủ nguồn')->toContain('writer.source_discovery')->toContain('Nghiên cứu lại')
        ->and($article['current_version_id'])->toBeNull()
        ->and(inResearchTenant($t, fn () => ContentBrief::query()->where('article_id', $id)->exists()))->toBeFalse()
        ->and($t['ai']->count('article_section'))->toBe(0);
    expect(inResearchTenant($t, fn () => ResearchDossier::query()->where('article_id', $id)->first()->warnings))->toContain('web_search_unavailable');
    // Discovery ran without web search (the gateway would refuse it) and was told so.
    $prompt = collect($t['ai']->calls)->firstWhere('schema', 'source_candidates')['prompt'];
    expect($prompt)->not->toContain('DÙNG công cụ tìm kiếm web');

    // Add sources → research again → the article goes on to the draft.
    $this->actingAs($t['owner'])->patchJson(writerUrl($t, "articles/{$id}"), ['source_urls' => [AX210_URL, AX200_URL], 'allow_web_search' => false])->assertOk()
        ->assertJsonPath('data.settings.source_urls', [AX210_URL, AX200_URL]);
    $this->actingAs($t['owner'])->postJson(writerUrl($t, "articles/{$id}/workflows/resume"))->assertStatus(202);

    $article = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}"))->assertOk()->json('data');
    expect($article['status'])->toBe('NEEDS_HUMAN_REVIEW')
        ->and($article['blocked_reason'])->toBeNull()
        ->and($article['last_error'])->toBeNull()
        ->and(inResearchTenant($t, fn () => ResearchDossier::query()->where('article_id', $id)->count()))->toBe(2);
});

it('asks the model to search the web when a routed model can', function () {
    $t = writerTenant();
    discoveryCanSearch(true);

    createArticle($t, ['ai_source_discovery' => true])->assertCreated();

    $prompt = collect($t['ai']->calls)->firstWhere('schema', 'source_candidates')['prompt'];
    expect($prompt)->toContain('DÙNG công cụ tìm kiếm web');
});

it('looks up more sources by itself when the first pass is too thin', function () {
    $t = writerTenant();
    discoveryCanSearch(true);
    $t['ai']->on('source_candidates', fn ($r) => str_contains($r->prompt, 'Vòng tìm thứ hai')
        ? ['urls' => [['url' => AX210_URL, 'reason' => 'Intel'], ['url' => AX200_URL, 'reason' => 'Intel']]]
        : ['urls' => [['url' => 'https://www.intel.example/missing-page.html', 'reason' => 'guess']]]);

    $id = createArticle($t, ['review_mode' => 'final_only', 'source_urls' => [], 'ai_source_discovery' => true])->assertCreated()->json('data.id');
    $article = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}"))->assertOk()->json('data');

    expect($t['ai']->count('source_candidates'))->toBe(2)
        ->and($article['blocked_reason'])->toBeNull()
        ->and($article['status'])->toBe('NEEDS_HUMAN_REVIEW');
    $prompt = collect($t['ai']->calls)->where('schema', 'source_candidates')->last()['prompt'];
    expect($prompt)->toContain('missing-page.html'); // told which URLs were already tried
});

it('never searches the web when the Super Admin switch is off', function () {
    $t = writerTenant();
    discoveryCanSearch(true);
    app(WriterSettings::class)->save(null, ['web_search_enabled' => false], null);

    $id = createArticle($t, ['source_urls' => [], 'ai_source_discovery' => true, 'allow_web_search' => true])->assertCreated()->json('data.id');

    $prompts = collect($t['ai']->calls)->where('schema', 'source_candidates')->pluck('prompt');
    expect($prompts)->toHaveCount(1)->and($prompts->first())->not->toContain('DÙNG công cụ tìm kiếm web');
    $article = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}"))->assertOk()->json('data');
    expect($article['last_error']['message'])->toContain('Tìm kiếm web đang bị tắt');
});

it('can turn the evidence gate off (min_evidence_items = 0)', function () {
    $t = writerTenant();
    app(WriterSettings::class)->save(null, ['min_evidence_items' => 0], null);

    $id = createArticle($t, ['source_urls' => [], 'review_mode' => 'final_only'])->assertCreated()->json('data.id');
    $article = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}"))->assertOk()->json('data');
    expect($article['blocked_reason'])->toBeNull()->and($article['status'])->not->toBe('RESEARCH_COMPLETE');
});

it('rewrites a placeholder section once and blocks approval of placeholder text', function () {
    $t = writerTenant();
    $hollow = '<p>Bài này chưa có điều khoản chính thức của Microsoft trong tay, nên chưa thể kết luận.</p>'
        .'<table><tr><th>Tiêu chí</th><th>OEM</th></tr><tr><td>Hỗ trợ</td><td>Cần xác minh</td></tr><tr><td>Chuyển máy</td><td>Cần xác minh</td></tr></table>';
    $base = $t['ai']->handlers['article_section'];
    $t['ai']->on('article_section', function ($r) use ($base, $hollow) {
        $ctx = ScriptedAi::json($r->prompt);
        if (str_contains((string) ($ctx['section']['heading'] ?? ''), 'AX200')) {
            return isset($ctx['fix_placeholder']) ? ['html' => '<p>Intel AX200 chỉ dùng băng tần 2.4 GHz và 5 GHz.</p>', 'used_evidence_refs' => [], 'notes' => []]
                : ['html' => $hollow, 'used_evidence_refs' => [], 'notes' => []];
        }

        return $base($r);
    });

    $id = createArticle($t, ['review_mode' => 'final_only'])->assertCreated()->json('data.id');
    $html = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}/editor"))->assertOk()->json('data.version.content_html');
    expect($html)->not->toContain('Cần xác minh')->toContain('chỉ dùng băng tần');

    // A human pastes placeholder text back in → the approval gate refuses it.
    $codes = inResearchTenant($t, function () use ($id, $hollow) {
        $article = Article::query()->findOrFail($id);
        ArticleVersion::query()->whereKey($article->current_version_id)->update(['content_html' => '<h1>x</h1>'.$hollow]);

        return collect(app(ApprovalGate::class)->evaluate($article->refresh())['blocking'])->pluck('code')->all();
    });
    expect($codes)->toContain('placeholder_content');
});

it('detects placeholder content but not a single careful sentence', function () {
    $screenshot = '<p>Tuy nhiên, bài này chưa có điều khoản chính thức của Microsoft trong tay, nên chưa thể nêu định nghĩa chi tiết.</p>'
        .'<h3>Nhận định 1</h3><p>Chưa có nguồn chính thức, nên chưa thể kết luận.</p>';
    expect(PlaceholderDetector::scan($screenshot)['hollow'])->toBeTrue()
        ->and(PlaceholderDetector::scan('<table><tr><td>OEM</td><td>Cần xác minh</td><td>Cần xác minh</td></tr></table>')['cells'])->toBe(2)
        ->and(PlaceholderDetector::scan('<p>Windows OEM gắn với máy đầu tiên được kích hoạt. Hãy giữ hóa đơn khi mua.</p>')['hollow'])->toBeFalse()
        ->and(PlaceholderDetector::scan('<p>Thông số này cần xác minh trên trang của hãng trước khi mua.</p>')['hollow'])->toBeFalse();
});
