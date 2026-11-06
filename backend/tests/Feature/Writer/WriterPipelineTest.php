<?php

require_once __DIR__.'/helpers.php';

/*
 * Phase 5 acceptance: research → brief → outline → section drafts →
 * claims → fact-check → quality → human review, with evidence-first rules.
 */

it('writes an evidence-backed article end to end in final_only mode', function () {
    $t = writerTenant();

    $res = createArticle($t, ['review_mode' => 'final_only'], 'create-key-0001')->assertCreated();
    $id = $res->json('data.id');
    $article = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}"))->assertOk()->json('data');

    expect($article['status'])->toBe('NEEDS_HUMAN_REVIEW')
        ->and($article['current_version_id'])->not->toBeNull()
        ->and($article['budget']['spent_micro_usd'])->toBeGreaterThanOrEqual(0);

    // Research: every source was fetched by the system, evidence is verbatim from the snapshot.
    $research = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}/research"))->assertOk()->json('data');
    expect($research['usable_sources_count'])->toBe(2)
        ->and(collect($research['sources'])->pluck('retrieval_status')->unique()->all())->toBe(['fetched']);
    $evidence = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}/evidence"))->assertOk()->json('data');
    expect($evidence)->toHaveCount(3)->and(collect($evidence)->pluck('source.url')->unique()->sort()->values()->all())->toBe([AX200_URL, AX210_URL]);

    // Brief: a fact without valid evidence ref is moved to missing information; unknown links dropped.
    $brief = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}/brief"))->assertOk()->json('data.current');
    expect(collect($brief['data']['key_facts'])->pluck('fact')->all())->toBe(['AX210 hỗ trợ 6 GHz'])
        ->and(implode(' ', $brief['data']['missing_information']))->toContain('Bịa đặt không có nguồn')
        ->and($brief['data']['internal_links'])->toBe([]);

    // Content: citations point at the real source URL; nothing invented.
    $editor = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}/editor"))->assertOk()->json('data');
    $html = $editor['version']['content_html'];
    expect($html)->toContain('data-evidence-id')->toContain(AX210_URL)->toContain('<h2>Khác biệt với AX200</h2>')
        ->not->toContain('[E1]');

    // Claims were checked against evidence; model codes are not confused.
    $claims = collect($this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}/claims"))->assertOk()->json('data'));
    expect($claims->where('origin', 'ai'))->toHaveCount(2)
        ->and($claims->pluck('claim'))->not->toContain('AX210 hỗ trợ những băng tần nào?', 'Khác biệt với AX200')
        ->and($claims->where('origin', 'ai')->pluck('status')->unique()->all())->toBe(['VERIFIED'])
        ->and($claims->firstWhere('claim', 'Intel AX210 hỗ trợ băng tần 6 GHz')['latest_result']['supporting'][0]['url'])->toBe(AX210_URL);

    // Quality report exists but never claims rankings.
    $quality = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}/quality"))->assertOk()->json('data.current');
    expect($quality['score'])->toBeGreaterThan(0)->and($quality['disclaimer'])->toContain('không đảm bảo thứ hạng');

    // Idempotent create.
    expect(createArticle($t, ['review_mode' => 'final_only'], 'create-key-0001')->assertOk()->json('data.id'))->toBe($id);
});
