<?php

use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\Keyword;
use SiteXPost\Modules\Writer\Domain\Article;

require_once __DIR__.'/helpers.php';

/*
 * Bulk generation (preview, quota, duplicates, pause / cancel) and the
 * Super Admin writer console.
 */

/**
 * @return list<string> approved idea ids
 */
function approvedIdeas(array $t, int $n, string $status = 'approved'): array
{
    return inResearchTenant($t, function () use ($t, $n, $status): array {
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $kw = new Keyword;
            $kw->forceFill(['campaign_id' => $t['campaign']->id, 'keyword' => "intel ax210 bài {$i}", 'normalized' => "intel ax210 bai {$i}", 'fingerprint' => sha1("kw{$i}{$status}"),
                'language' => 'vi', 'country' => 'VN', 'intent' => 'informational', 'sources' => ['user'], 'primary_source' => 'user', 'status' => 'approved',
                'first_seen_at' => now(), 'data_collected_at' => now()])->save();
            $idea = new ContentIdea;
            $idea->forceFill(['campaign_id' => $t['campaign']->id, 'title' => "Intel AX210 chủ đề số {$i} {$status}", 'search_intent' => 'informational', 'content_type' => 'tutorial',
                'fingerprint' => sha1("idea{$i}{$status}"), 'status' => $status, 'source' => 'user', 'primary_keyword_id' => $kw->id])->save();
            $ids[] = $idea->id;
        }

        return $ids;
    });
}

it('previews cost and duplicates, then writes approved ideas in a batch that stops at human review', function () {
    $t = writerTenant();
    $ideas = approvedIdeas($t, 3);
    $suggested = approvedIdeas($t, 1, 'suggested');

    $preview = $this->actingAs($t['editor'])->postJson(writerUrl($t, 'batches/preview'), ['campaign_id' => $t['campaign']->id, 'content_idea_ids' => [...$ideas, ...$suggested]])
        ->assertOk()->json('data');
    expect($preview['creatable'])->toBe(3)->and($preview['not_approved_or_missing'])->toBe($suggested)
        ->and($preview['estimated_cost_micro_usd'])->toBeGreaterThan(0);

    $batch = $this->actingAs($t['editor'])->postJson(writerUrl($t, 'batches'), ['campaign_id' => $t['campaign']->id, 'content_idea_ids' => $ideas, 'concurrency' => 2,
        'settings' => ['target_words' => 600]], ['Idempotency-Key' => 'batch-key-0001'])->assertStatus(202)->json('data');
    $batch = $this->actingAs($t['editor'])->getJson(writerUrl($t, "batches/{$batch['id']}"))->assertOk()->json('data');

    expect($batch['status'])->toBe('completed')->and($batch['counts'])->toBe(['succeeded' => 3]);
    $statuses = inResearchTenant($t, fn () => Article::query()->where('writing_batch_id', $batch['id'])->pluck('status')->map(fn ($s) => $s->value)->unique()->values()->all());
    expect($statuses)->toBe(['NEEDS_HUMAN_REVIEW']);

    // Same key → same batch; the ideas are now taken → nothing new to create.
    expect($this->actingAs($t['editor'])->postJson(writerUrl($t, 'batches'), ['campaign_id' => $t['campaign']->id, 'content_idea_ids' => $ideas], ['Idempotency-Key' => 'batch-key-0001'])
        ->assertOk()->json('data.id'))->toBe($batch['id']);
    $this->actingAs($t['editor'])->postJson(writerUrl($t, 'batches'), ['campaign_id' => $t['campaign']->id, 'content_idea_ids' => $ideas])->assertStatus(422);

    // A viewer cannot start bulk generation.
    $this->actingAs($t['viewer'])->postJson(writerUrl($t, 'batches'), ['campaign_id' => $t['campaign']->id, 'content_idea_ids' => $ideas])->assertForbidden();
});

it('cancels a paused batch and gives back the quota of items never started', function () {
    $t = writerTenant();
    $ideas = approvedIdeas($t, 3);
    $batch = $this->actingAs($t['editor'])->postJson(writerUrl($t, 'batches'), ['campaign_id' => $t['campaign']->id, 'content_idea_ids' => $ideas, 'concurrency' => 1])->json('data');
    // (sync queue: items complete immediately; pause/cancel on a completed batch is a no-op)
    $this->actingAs($t['editor'])->postJson(writerUrl($t, "batches/{$batch['id']}/pause"))->assertOk();
    $this->actingAs($t['editor'])->postJson(writerUrl($t, "batches/{$batch['id']}/cancel"))->assertOk();
    $quota = $this->actingAs($t['owner'])->getJson(writerUrl($t, 'overview'))->assertOk()->json('data.quota');
    expect($quota['used'])->toBe(3);
});

it('gives Super Admins settings, routing, costs, monitoring, failures and abuse views', function () {
    $t = writerTenant();
    createArticle($t, ['review_mode' => 'final_only'])->assertCreated();
    $admin = User::factory()->platform(PlatformRole::SuperAdmin, true)->create();

    $this->actingAs($t['owner'])->getJson('/api/admin/v1/writer/settings')->assertNotFound();

    $this->actingAs($admin)->getJson('/api/admin/v1/writer/settings')->assertOk()->assertJsonPath('data.values.max_revision_rounds', 2);
    $this->actingAs($admin)->patchJson('/api/admin/v1/writer/settings', ['min_quality_score_for_approval' => 70])->assertOk()->assertJsonPath('data.values.min_quality_score_for_approval', 70);
    $this->actingAs($admin)->patchJson('/api/admin/v1/writer/settings', ['default_article_budget_micro_usd' => 99_000_000])->assertStatus(422);
    $this->actingAs($admin)->patchJson("/api/admin/v1/writer/workspaces/{$t['workspace']->id}/settings", ['max_articles_per_month' => 5, 'min_quality_score_for_approval' => 1])
        ->assertOk()->assertJsonPath('data.values.max_articles_per_month', 5)->assertJsonPath('data.values.min_quality_score_for_approval', 70);

    $routing = collect($this->actingAs($admin)->getJson('/api/admin/v1/writer/routing')->assertOk()->json('data'));
    expect($routing->pluck('task_key'))->toContain('writer.claims', 'verification.claims', 'content.draft')->and($routing->every(fn ($r) => $r['configured']))->toBeTrue();

    $costs = $this->actingAs($admin)->getJson('/api/admin/v1/writer/costs')->assertOk()->json('data');
    expect($costs['articles'])->toBe(1)->and($costs['by_task'])->not->toBeEmpty();
    $this->actingAs($admin)->getJson('/api/admin/v1/writer/runs')->assertOk()->assertJsonCount(3, 'data');
    $this->actingAs($admin)->getJson('/api/admin/v1/writer/failures')->assertOk()->assertJsonStructure(['data' => ['workflow_errors', 'source_retrieval', 'verdicts', 'quality']]);
    $this->actingAs($admin)->getJson('/api/admin/v1/writer/abuse')->assertOk()->assertJsonPath('data', []);
});
