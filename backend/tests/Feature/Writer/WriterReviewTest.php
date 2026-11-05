<?php

use SiteXPost\Modules\AiGateway\Providers\ProviderRequest;
use SiteXPost\Modules\Writer\Domain\EditorialApproval;

require_once __DIR__.'/helpers.php';

/*
 * Human review: step-by-step gates, approval policy, edits invalidate
 * approval and fact-check, manual verification, AI rewrite proposals.
 */

function articleJson(array $t, string $id, string $path = ''): array
{
    return test()->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}".($path === '' ? '' : '/'.$path)))->assertOk()->json('data');
}

function writtenArticle(array $t, array $extra = []): string
{
    return createArticle($t, $extra + ['review_mode' => 'final_only'])->assertCreated()->json('data.id');
}

it('pauses for brief and outline approval in step_by_step mode', function () {
    $t = writerTenant();
    $id = createArticle($t)->assertCreated()->json('data.id');

    $a = articleJson($t, $id);
    expect($a['status'])->toBe('BRIEF_READY')->and($a['waiting_for'])->toBe('brief_approval')->and($a['current_version_id'])->toBeNull();

    // The editor changes the brief; a fact without evidence cannot be smuggled in.
    $this->actingAs($t['editor'])->postJson(writerUrl($t, "articles/{$id}/brief/approve"), ['data' => [
        'audience' => 'Game thủ', 'key_facts' => [['fact' => 'AX210 nhanh gấp 10 lần', 'evidence_refs' => [42]]],
    ]])->assertStatus(202);
    $brief = articleJson($t, $id, 'brief')['current'];
    expect($brief['version'])->toBe(2)->and($brief['status'])->toBe('approved')->and($brief['data']['audience'])->toBe('Game thủ')
        ->and($brief['data']['key_facts'])->toBe([])
        ->and(implode(' ', $brief['data']['missing_information']))->toContain('AX210 nhanh gấp 10 lần');

    $a = articleJson($t, $id);
    expect($a['status'])->toBe('OUTLINING')->and($a['waiting_for'])->toBe('outline_approval');

    // A viewer cannot approve the outline; the editor can.
    $this->actingAs($t['viewer'])->postJson(writerUrl($t, "articles/{$id}/outline/approve"))->assertForbidden();
    // Editing the outline round-trips what the UI sends back (facts, word range, subheadings).
    $sections = articleJson($t, $id, 'outline')['current']['sections'];
    $withFacts = collect($sections)->first(fn ($s) => $s['required_facts'] !== []);
    expect($withFacts)->not->toBeNull();
    $sections = array_map(fn ($s) => $s['key'] === $withFacts['key'] ? ['heading' => 'Tiêu đề đã sửa', 'word_range' => [200, 400], 'subheadings' => ['Ý phụ']] + $s : $s, $sections);
    $this->actingAs($t['editor'])->postJson(writerUrl($t, "articles/{$id}/outline/approve"), ['sections' => $sections])->assertStatus(202);
    $edited = collect(articleJson($t, $id, 'outline')['current']['sections'])->firstWhere('heading', 'Tiêu đề đã sửa');
    expect($edited['required_facts'])->toBe($withFacts['required_facts'])->and($edited['evidence_refs'])->toBe($withFacts['evidence_refs'])
        ->and($edited['word_range'])->toBe([200, 400])->and($edited['subheadings'])->toBe(['Ý phụ']);

    expect(articleJson($t, $id)['status'])->toBe('NEEDS_HUMAN_REVIEW');
});

it('approves only with the publish permission and invalidates the approval when content changes', function () {
    $t = writerTenant();
    $id = writtenArticle($t);
    $a = articleJson($t, $id);
    $versionId = $a['current_version_id'];

    $check = articleJson($t, $id, 'approval');
    expect($check['ok'])->toBeTrue()->and($check['can_approve'])->toBeTrue()->and($check['stats']['unresolved_critical'])->toBe(0);

    // EDITOR lacks content.publish.
    $this->actingAs($t['editor'])->postJson(writerUrl($t, "articles/{$id}/approve"), ['version_id' => $versionId, 'acknowledge_high_risk' => true])->assertForbidden();

    $this->actingAs($t['owner'])->postJson(writerUrl($t, "articles/{$id}/approve"), ['version_id' => $versionId, 'acknowledge_high_risk' => true])->assertOk();
    expect(articleJson($t, $id)['status'])->toBe('APPROVED');

    // Editing a section after approval → back to review, approval invalidated, re-check required.
    $html = articleJson($t, $id, 'editor')['version']['content_html'];
    $edited = str_replace('Đây là điểm khác biệt chính của chuẩn WiFi 6E.', 'Đây là điểm khác biệt chính của chuẩn WiFi 6E, ra mắt năm 2020.', $html);
    $this->actingAs($t['editor'])->postJson(writerUrl($t, "articles/{$id}/versions"), ['content_html' => $edited, 'base_version_id' => $versionId])->assertCreated();

    $a = articleJson($t, $id);
    expect($a['status'])->toBe('NEEDS_HUMAN_REVIEW')->and($a['approved_version_id'])->toBeNull()
        ->and(inResearchTenant($t, fn () => EditorialApproval::query()->where('decision', 'approved')->sole()->invalidated_at))->not->toBeNull();
    $check = articleJson($t, $id, 'approval');
    expect(collect($check['blocking'])->pluck('code')->all())->toContain('sections_not_checked');

    // Saving against a stale base version is a conflict, nothing is lost.
    $this->actingAs($t['editor'])->postJson(writerUrl($t, "articles/{$id}/versions"), ['content_html' => $html.'<p>x</p>', 'base_version_id' => $versionId])
        ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict');

    // Re-check only the changed section, then the article can be approved again.
    $this->actingAs($t['editor'])->postJson(writerUrl($t, "articles/{$id}/workflows"), ['kind' => 'recheck'])->assertStatus(202);
    $a = articleJson($t, $id);
    expect($a['status'])->toBe('NEEDS_HUMAN_REVIEW');
    expect(articleJson($t, $id, 'approval')['blocking'])->not->toContain(fn ($b) => $b['code'] === 'sections_not_checked');
    $this->actingAs($t['owner'])->postJson(writerUrl($t, "articles/{$id}/approve"), ['version_id' => $a['current_version_id'], 'acknowledge_high_risk' => true])->assertOk();
});

it('blocks approval while a CRITICAL claim is unresolved; manual verification needs an approver and evidence', function () {
    $t = writerTenant();
    $t['ai']->on('article_section', fn (ProviderRequest $r) => ['html' => '<p>Theo Nghị định 13/2023/NĐ-CP, cửa hàng phải xin phép trước khi thu thập dữ liệu cá nhân của khách.</p>', 'used_evidence_refs' => [], 'notes' => []]);
    $id = writtenArticle($t);

    $critical = collect(articleJson($t, $id, 'claims'))->firstWhere('risk_level', 'CRITICAL');
    expect($critical)->not->toBeNull()->and($critical['status'])->toBe('INSUFFICIENT_EVIDENCE');

    $check = articleJson($t, $id, 'approval');
    expect(collect($check['blocking'])->pluck('code')->all())->toContain('critical_unresolved');
    $this->actingAs($t['owner'])->postJson(writerUrl($t, "articles/{$id}/approve"), ['version_id' => articleJson($t, $id)['current_version_id'], 'acknowledge_high_risk' => true])
        ->assertStatus(422)->assertJsonPath('error.code', 'approval_blocked');

    $url = writerUrl($t, "articles/{$id}/claims/{$critical['id']}/verify");
    $this->actingAs($t['editor'])->postJson($url, ['verdict' => 'VERIFIED', 'note' => 'Đã đối chiếu văn bản gốc.', 'reference_url' => 'https://vanban.chinhphu.vn/nd-13-2023'])->assertForbidden();
    $this->actingAs($t['owner'])->postJson($url, ['verdict' => 'VERIFIED', 'note' => 'Đã đối chiếu văn bản gốc.'])->assertStatus(422);
    $this->actingAs($t['owner'])->postJson($url, ['verdict' => 'VERIFIED', 'note' => 'Đã đối chiếu văn bản gốc.', 'reference_url' => 'https://vanban.chinhphu.vn/nd-13-2023'])
        ->assertOk()->assertJsonPath('data.status', 'VERIFIED')->assertJsonPath('data.latest_result.reviewer_type', 'human');

    expect(collect(articleJson($t, $id, 'approval')['blocking'])->pluck('code')->all())->not->toContain('critical_unresolved');
});

it('proposes AI rewrites without touching content until applied', function () {
    $t = writerTenant();
    $id = writtenArticle($t);
    $before = articleJson($t, $id, 'editor')['version'];
    $section = collect($before['sections'])->firstWhere('heading', 'AX210 hỗ trợ những băng tần nào?')['key'];

    $rev = $this->actingAs($t['editor'])->postJson(writerUrl($t, "articles/{$id}/revisions"), ['action' => 'shorten', 'section_key' => $section])->assertStatus(202)->json('data');
    $rev = articleJson($t, $id, "revisions/{$rev['id']}");
    expect($rev['status'])->toBe('proposed')->and($rev['diff'])->not->toBeEmpty()
        ->and(articleJson($t, $id)['current_version_id'])->toBe($before['id']);

    $this->actingAs($t['editor'])->postJson(writerUrl($t, "articles/{$id}/revisions/{$rev['id']}/apply"))->assertCreated();
    $after = articleJson($t, $id, 'editor')['version'];
    expect($after['number'])->toBe($before['number'] + 1)->and($after['content_html'])->toContain('AX210 là card WiFi 6E')
        ->and(collect($after['sections'])->firstWhere('key', $section)['claims_checked'])->toBeFalse();

    // Restore the previous version → a new version with the old content.
    $this->actingAs($t['editor'])->postJson(writerUrl($t, "articles/{$id}/versions/{$before['id']}/restore"))->assertCreated();
    $diff = $this->actingAs($t['owner'])->getJson(writerUrl($t, "articles/{$id}/diff").'?from='.$before['id'])->assertOk()->json('data');
    expect($diff['sections'])->toBe([]);
});

it('sanitizes editor HTML and keeps autosave behind an optimistic lock', function () {
    $t = writerTenant();
    $id = writtenArticle($t);
    $ed = articleJson($t, $id, 'editor');
    $html = $ed['version']['content_html'].'<p onclick="steal()">Xin chào<script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)">x</a><iframe src="https://evil.example"></iframe></p>';

    $this->actingAs($t['editor'])->putJson(writerUrl($t, "articles/{$id}/autosave"), ['content_html' => $html, 'base_version_id' => $ed['version']['id'], 'lock_version' => $ed['lock_version']])->assertOk();
    $this->actingAs($t['editor'])->putJson(writerUrl($t, "articles/{$id}/autosave"), ['content_html' => $html, 'base_version_id' => $ed['version']['id'], 'lock_version' => $ed['lock_version']])->assertStatus(409);

    $auto = articleJson($t, $id, 'editor')['autosave']['content_html'];
    $v = $this->actingAs($t['editor'])->postJson(writerUrl($t, "articles/{$id}/versions"), ['content_html' => $html, 'base_version_id' => $ed['version']['id']])->assertCreated()->json('data.version.content_html');
    foreach ([$auto, $v] as $out) {
        expect($out)->not->toContain('<script')->not->toContain('onclick')->not->toContain('onerror')->not->toContain('javascript:')->not->toContain('<iframe')->toContain('Xin chào');
    }
});

it('lists the workspace writing history with filters', function () {
    $t = writerTenant();
    $id = writtenArticle($t);

    $rows = $this->actingAs($t['viewer'])->getJson(writerUrl($t, 'history'))->assertOk()->json('data');
    expect($rows)->not->toBeEmpty()->and(collect($rows)->pluck('article.id')->unique()->all())->toBe([$id])
        ->and(collect($rows)->pluck('kind')->all())->toContain('research');

    $drafts = $this->actingAs($t['viewer'])->getJson(writerUrl($t, 'history?kind=draft&status=succeeded'))->assertOk()->json('data');
    expect(collect($drafts)->pluck('kind')->unique()->all())->toBe(['draft']);
});
