<?php

use SiteXPost\Modules\Seo\Audit\SeoRules;
use SiteXPost\Modules\Seo\Support\ArticleHtml;
use SiteXPost\Modules\Seo\Support\InternalLinkEngine;
use SiteXPost\Modules\Seo\Support\MetadataGenerator;
use SiteXPost\Modules\Seo\Support\SchemaBuilder;

require_once __DIR__.'/helpers.php';

it('runs an explainable audit of the publishable render with a checklist score', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);
    $res = $this->actingAs($t['editor'])->postJson(p6url($t['workspace'], 'seo/articles/'.$article.'/audits'))->assertCreated();
    $issues = collect($res->json('data.issues'));
    expect($issues)->toHaveCount(19)
        ->and($issues->every(fn ($i) => in_array($i['severity'], ['PASS', 'WARNING', 'ERROR', 'BLOCKED'], true) && $i['explanation'] !== ''))->toBeTrue()
        ->and($res->json('data.score'))->toBeGreaterThan(50)->toBeLessThanOrEqual(100)
        ->and($res->json('data.disclaimer'))->toContain('không bảo đảm thứ hạng')
        ->and($issues->firstWhere('rule', 'broken_links')['severity'])->toBe('WARNING')
        ->and($issues->firstWhere('rule', 'h1')['severity'])->toBe('PASS');
    $this->actingAs($t['viewer'])->postJson(p6url($t['workspace'], 'seo/articles/'.$article.'/audits'))->assertForbidden();
    $this->actingAs($t['viewer'])->getJson(p6url($t['workspace'], 'seo/articles/'.$article.'/audits'))->assertOk()->assertJsonCount(1, 'data');
});

it('refuses dangerous canonical / noindex changes unless the site policy allows them', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);
    $meta = $this->actingAs($t['editor'])->getJson(p6url($t['workspace'], 'seo/articles/'.$article.'/metadata'))->assertOk()->json('data');
    expect($meta['slug'])->toBe('may-loc-nuoc')->and(mb_strlen($meta['seo_title']))->toBeLessThanOrEqual(60)->and(mb_strlen($meta['meta_description']))->toBeLessThanOrEqual(156);

    $url = p6url($t['workspace'], 'seo/articles/'.$article.'/metadata');
    $this->actingAs($t['editor'])->putJson($url, ['lock_version' => $meta['lock_version'], 'canonical_url' => 'https://other.example/x'])
        ->assertStatus(422)->assertJsonPath('error.code', 'canonical_requires_policy');
    $this->actingAs($t['editor'])->putJson($url, ['lock_version' => $meta['lock_version'], 'robots_noindex' => true])
        ->assertStatus(422)->assertJsonPath('error.code', 'robots_requires_policy');

    $this->actingAs($t['editor'])->putJson(p6url($t['workspace'], 'seo/sites/'.$t['site']->id.'/config'), ['allow_robots_override' => true])->assertForbidden();
    $this->actingAs($t['owner'])->putJson(p6url($t['workspace'], 'seo/sites/'.$t['site']->id.'/config'), ['allow_robots_override' => true])->assertOk();
    $saved = $this->actingAs($t['editor'])->putJson($url, ['lock_version' => $meta['lock_version'], 'robots_noindex' => true, 'seo_title' => 'Tiêu đề do người sửa'])->assertOk()->json('data');
    expect($saved['manual_fields'])->toContain('seo_title', 'robots_noindex');

    // Stale lock → 409; regenerate keeps manual fields.
    $this->actingAs($t['editor'])->putJson($url, ['lock_version' => $meta['lock_version'], 'seo_title' => 'x'])->assertStatus(409);
    $regen = $this->actingAs($t['editor'])->postJson(p6url($t['workspace'], 'seo/articles/'.$article.'/metadata/generate'))->assertOk()->json('data');
    expect($regen['seo_title'])->toBe('Tiêu đề do người sửa')->and($regen['robots_noindex'])->toBeTrue();
});

it('blocks a cross-domain canonical in the audit even if stored before the policy changed', function () {
    $issues = (new SeoRules)->run([
        'title' => 'Tiêu đề', 'html' => ArticleHtml::parse('<p>'.str_repeat('chữ ', 400).'</p>'), 'meta' => ['canonical_url' => 'https://evil.example/a', 'robots_noindex' => true, 'slug' => 'a'],
        'article' => [], 'site' => ['host' => 'blog.example.com'], 'policy' => ['allow_canonical_override' => false, 'allow_robots_override' => false],
        'inventory' => [], 'own_wp_post_id' => null, 'images' => [], 'featured' => null, 'link_status' => null, 'schema' => ['enabled' => false], 'sources' => 0,
    ]);
    $by = collect($issues)->keyBy('rule');
    expect($by['canonical']->severity)->toBe('BLOCKED')->and($by['indexability']->severity)->toBe('BLOCKED');
});

it('builds schema only when enabled, never duplicates an SEO plugin and only for eligible content', function () {
    $b = new SchemaBuilder;
    $html = phase6Html();
    $article = ['content_type' => 'guide', 'language' => 'vi'];
    $meta = ['schema_types' => ['BlogPosting', 'FAQPage', 'HowTo'], 'meta_description' => 'Mô tả'];
    expect($b->build(['injection_enabled' => false], [], $article, 'T', $html, $meta)['graph'])->toBe([]);

    $on = ['injection_enabled' => true, 'enabled_types' => SchemaBuilder::TYPES, 'organization_name' => 'Cửa hàng'];
    $yoast = $b->build($on, ['yoast' => true, 'host' => 'blog.example.com'], $article, 'T', $html, $meta);
    expect($yoast['types'])->toBe(['FAQPage'])->and($yoast['skipped'])->toMatchArray(['BlogPosting' => 'seo_plugin_outputs', 'HowTo' => 'not_eligible'])
        ->and($yoast['graph'][0]['mainEntity'])->toHaveCount(2)
        ->and($yoast['graph'][0]['mainEntity'][0]['name'])->toBe('Bao lâu thay lõi một lần?');

    $native = $b->build($on, ['yoast' => false, 'rank_math' => false, 'host' => 'blog.example.com'], $article, 'T', $html, $meta);
    expect($native['types'])->toContain('BlogPosting')->and($native['graph'][0]['author']['@type'])->toBe('Organization');

    $oneQ = '<h2>Câu hỏi thường gặp</h2><h3>Một câu?</h3><p>Một câu trả lời đủ dài để hợp lệ.</p>';
    expect($b->build($on, [], $article, 'T', $oneQ, ['schema_types' => ['FAQPage']])['skipped'])->toBe(['FAQPage' => 'not_eligible']);
});

it('suggests internal links only to real published URLs, with anchors that already exist in the text', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);
    $res = $this->actingAs($t['editor'])->postJson(p6url($t['workspace'], 'seo/articles/'.$article.'/links/suggest'))->assertOk();
    $s = $res->json('data.0');
    expect($res->json('suggested'))->toBe(1)->and($s['target_wp_post_id'])->toBe(7)->and($s['target_url'])->toContain('thay-loi-may-loc-nuoc')
        ->and(phase6Html())->toContain($s['anchor_text'])->and(mb_strtolower($s['anchor_text']))->not->toBe('máy lọc nước')->and($s['reason'])->not->toBe('');

    $this->actingAs($t['viewer'])->patchJson(p6url($t['workspace'], 'seo/links/'.$s['id']), ['status' => 'accepted'])->assertForbidden();
    $this->actingAs($t['editor'])->patchJson(p6url($t['workspace'], 'seo/links/'.$s['id']), ['status' => 'accepted'])->assertOk();
    $preview = $this->actingAs($t['editor'])->getJson(p6url($t['workspace'], 'publishing/articles/'.$article.'/preview'))->json('data');
    expect($preview['html'])->toContain('href="'.$s['target_url'].'"')->and($preview['links'])->toHaveCount(1);
});

it('keeps generated metadata within search snippet limits and slugs ASCII', function () {
    expect(MetadataGenerator::slug('Đánh giá máy lọc nước RO tốt nhất 2026'))->toBe('danh-gia-may-loc-nuoc-ro-tot-nhat-2026')
        ->and(mb_strlen(MetadataGenerator::title(str_repeat('Tiêu đề rất dài ', 10), 'máy lọc')))->toBeLessThanOrEqual(60)
        ->and(InternalLinkEngine::anchor('Bạn nên thay lõi đúng hạn để nước sạch.', ['thay', 'loi']))->toBe('thay lõi');
});
