<?php

use SiteXPost\Modules\Analytics\Domain\ContentRefreshJob;

require_once __DIR__.'/../Phase6/helpers.php';
require_once __DIR__.'/helpers.php';

/*
 * Phase 8 content refresh: Writer revision + fact-check, human approval,
 * SEO audit, Publisher update. Never overwrites a post edited in WordPress.
 */

function publishedRefreshTenant(object $test, array $policy = []): array
{
    seedAi();
    $t = publishingTenant($policy);
    $article = approvedArticle($t['workspace'], $t['site']);
    $job = $test->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs'), ['article_id' => $article, 'mode' => 'publish_now'])->assertCreated()->json('data.id');
    $test->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$job.'/approve'))->assertOk();

    return $t + ['article' => $article];
}

function refreshJob(array $t): ContentRefreshJob
{
    return inAnalyticsTenant($t, fn () => ContentRefreshJob::query()->latest('id')->firstOrFail());
}

it('drafts a refresh through the Writer, waits for a human, then updates WordPress', function () {
    $t = publishedRefreshTenant($this, ['require_seo_pass' => false]);
    $res = $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs'), ['article_id' => $t['article']])->assertStatus(202);
    $id = $res->json('data.id');
    $job = refreshJob($t);
    expect($job->id)->toBe($id)->and($job->status)->toBe('awaiting_approval')
        ->and($job->brief)->not->toBeEmpty()->and($job->revision_ids)->not->toBeEmpty()
        ->and($job->new_version_id)->not->toBeNull()->and($job->diff)->not->toBeEmpty();

    // A second refresh of the same article is refused while one is open.
    $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs'), ['article_id' => $t['article']])
        ->assertStatus(409)->assertJsonPath('error.code', 'refresh_in_progress');

    // Editors cannot approve (content.publish needed); viewers cannot even start one.
    $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs/'.$id.'/approve'))->assertForbidden();
    $this->actingAs($t['viewer'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs'), ['article_id' => $t['article']])->assertForbidden();

    // Nothing reaches WordPress before approval + publish.
    $wpId = array_key_last($t['wp']->posts);
    $before = $t['wp']->posts[$wpId]['content'] ?? null;
    expect(refreshJob($t)->status)->toBe('awaiting_approval');

    $this->actingAs($t['owner'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs/'.$id.'/approve'), ['note' => 'ok'])->assertStatus(202);
    $job = refreshJob($t);
    expect($job->status)->toBeIn(['awaiting_publish', 'seo_blocked'])->and($job->approved_by)->toBe($t['owner']->id);
    expect($t['wp']->posts[$wpId]['content'] ?? null)->toBe($before);

    expect($job->status)->toBe('awaiting_publish');
    $this->actingAs($t['owner'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs/'.$id.'/publish'))->assertOk();
    $job = refreshJob($t);
    expect($job->status)->toBe('monitoring')->and($job->experiment_id)->not->toBeNull()->and($job->active_key)->toBeNull();
    expect($t['wp']->posts[$wpId]['content'])->not->toBe($before);
});

it('keeps the refresh waiting when the Publisher gates fail at publish time', function () {
    $t = publishedRefreshTenant($this, ['require_seo_pass' => true]);
    $id = $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs'), ['article_id' => $t['article']])->json('data.id');
    $this->actingAs($t['owner'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs/'.$id.'/approve'))->assertStatus(202);
    $job = refreshJob($t);
    if ($job->status === 'seo_blocked') {
        expect($job->error_code)->toBe('seo_audit_blocked');

        return;
    }
    $wpId = array_key_last($t['wp']->posts);
    $before = $t['wp']->posts[$wpId]['content'];
    $this->actingAs($t['owner'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs/'.$id.'/publish'))
        ->assertStatus(422)->assertJsonPath('error.code', 'gates_failed');
    expect(refreshJob($t)->status)->toBe('awaiting_publish')->and($t['wp']->posts[$wpId]['content'])->toBe($before);
});

it('refuses to overwrite a post edited in WordPress after the refresh started', function () {
    $t = publishedRefreshTenant($this);
    $id = $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs'), ['article_id' => $t['article']])->assertStatus(202)->json('data.id');
    expect(refreshJob($t)->status)->toBe('awaiting_approval');

    $wpId = array_key_last($t['wp']->posts);
    $t['wp']->editInWordPress($wpId, ['content' => '<p>Sửa tay trên WordPress</p>']);

    $this->actingAs($t['owner'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs/'.$id.'/approve'))->assertStatus(202);
    $job = refreshJob($t);
    expect($job->status)->toBe('conflict')->and($job->error_code)->toBe('wordpress_changed');
    expect($t['wp']->posts[$wpId]['content'])->toBe('<p>Sửa tay trên WordPress</p>');
});

it('only refreshes approved, published articles', function () {
    seedAi();
    $t = publishingTenant();
    $draft = approvedArticle($t['workspace'], $t['site'], ['status' => 'WRITING']);
    $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs'), ['article_id' => $draft])
        ->assertStatus(409)->assertJsonPath('error.code', 'refresh_requires_approved_article');
    $unpublished = approvedArticle($t['workspace'], $t['site']);
    $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs'), ['article_id' => $unpublished])
        ->assertStatus(409)->assertJsonPath('error.code', 'refresh_not_published');
});

it('lets an editor cancel an open refresh', function () {
    $t = publishedRefreshTenant($this);
    $id = $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs'), ['article_id' => $t['article']])->json('data.id');
    $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs/'.$id.'/cancel'))->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(refreshJob($t)->active_key)->toBeNull();
    $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], 'analytics/refresh-jobs/'.$id.'/cancel'))->assertStatus(409);
});
