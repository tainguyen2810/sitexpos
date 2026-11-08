<?php

use Illuminate\Support\Facades\Artisan;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Publishing\Actions\ExecutePublish;
use SiteXPost\Modules\Publishing\Domain\PublishingAttempt;
use SiteXPost\Modules\Publishing\Domain\PublishingConflict;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\PublishingSchedule;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Support\PublishingStatus;

require_once __DIR__.'/helpers.php';

function createJob($test, array $t, string $article, string $mode = 'wp_draft', array $extra = [], string $as = 'owner')
{
    return $test->actingAs($t[$as])->postJson(p6url($t['workspace'], 'publishing/jobs'), ['article_id' => $article, 'mode' => $mode] + $extra);
}

function inWs(array $t, callable $fn): mixed
{
    return app(TenantContext::class)->runAs($t['workspace'], null, $fn);
}

it('saves an approved article as a WordPress draft through every gate, with SEO and schema', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);

    $res = createJob($this, $t, $article, 'wp_draft')->assertCreated();
    expect($res->json('data.status'))->toBe('READY_FOR_REVIEW')
        ->and(collect($res->json('data.gate_results'))->every(fn ($g) => $g['ok']))->toBeTrue();

    $res = $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$res->json('data.id').'/approve'))->assertOk();
    expect($res->json('data.status'))->toBe('PUBLISHED')->and($t['wp']->posts)->toHaveCount(1);
    $post = array_values($t['wp']->posts)[0];
    expect($post['status'])->toBe('draft')
        ->and($post['content'])->not->toContain('<h1>')
        ->and($post['seo']['title'] ?? '')->not->toBe('')
        ->and($post['slug'])->toBe('may-loc-nuoc');

    $mapping = inWs($t, fn () => WordPressPostMapping::query()->sole());
    expect($mapping->wp_post_id)->toBe(array_key_first($t['wp']->posts))
        ->and($mapping->last_synced_hash)->toBe(hash('sha256', $post['content']));
});

it('refuses to publish an unapproved article (no bypass); open fact-check issues only warn', function () {
    $t = publishingTenant();
    $draft = approvedArticle($t['workspace'], $t['site'], ['status' => 'NEEDS_HUMAN_REVIEW', 'title' => 'Bài chưa duyệt']);
    $res = createJob($this, $t, $draft, 'publish_now')->assertCreated();
    expect($res->json('data.status'))->toBe('DRAFT')->and($res->json('data.blocked_reason'))->toBe('article_not_approved');
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$res->json('data.id').'/approve'))->assertStatus(422)->assertJsonPath('error.code', 'gates_failed');

    $risky = approvedArticle($t['workspace'], $t['site'], ['critical' => 1, 'title' => 'Bài còn claim']);
    $res = createJob($this, $t, $risky, 'wp_draft')->assertCreated();
    expect($res->json('data.blocked_reason'))->not->toBe('unresolved_claims');
    $gate = collect($res->json('data.gate_results'))->firstWhere('gate', 'fact_check');
    expect($gate['ok'])->toBeTrue()->and($gate['details']['warning'])->toBeTrue()->and($gate['details']['critical'])->toBe(1);
    expect($t['wp']->posts)->toHaveCount(0);
});

it('lets only content.publish roles approve; editors prepare, viewers read', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);
    createJob($this, $t, $article, 'wp_draft', [], 'viewer')->assertForbidden();
    $id = createJob($this, $t, $article, 'wp_draft', [], 'editor')->assertCreated()->json('data.id');
    $this->actingAs($t['editor'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/approve'))->assertForbidden();
    $this->actingAs($t['viewer'])->getJson(p6url($t['workspace'], 'publishing/jobs/'.$id))->assertOk();
    $this->actingAs($t['admin'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/approve'))->assertOk();
    $this->actingAs($t['editor'])->putJson(p6url($t['workspace'], 'publishing/sites/'.$t['site']->id.'/policy'), ['max_posts_per_day' => 3])->assertForbidden();
    expect($t['wp']->posts)->toHaveCount(1);
});

it('allows only one active job per article and site', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);
    createJob($this, $t, $article, 'scheduled', ['scheduled_at' => now()->addDay()->toIso8601String()])->assertCreated();
    createJob($this, $t, $article, 'wp_draft')->assertStatus(409)->assertJsonPath('error.code', 'active_job_exists');
});

it('never duplicates a post when the first write times out: it reconciles with WordPress first', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);
    $t['wp']->timeoutAfterWriteRoute = '/sitex-post/v1/posts';
    $id = createJob($this, $t, $article, 'wp_draft')->json('data.id');
    $res = $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/approve'))->assertOk();

    expect($res->json('data.status'))->toBe('PUBLISHED')->and($t['wp']->posts)->toHaveCount(1);
    $attempt = inWs($t, fn () => PublishingAttempt::query()->sole());
    expect($attempt->outcome)->toBe('reconciled')->and($attempt->error_code)->toBe('unreachable');
});

it('retries with backoff when the site is offline and fails after max attempts without looping', function () {
    $t = publishingTenant(['max_attempts' => 2]);
    $article = approvedArticle($t['workspace'], $t['site']);
    $id = createJob($this, $t, $article, 'wp_draft')->json('data.id');
    $t['wp']->online = false;
    $res = $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/approve'))->assertOk();
    expect($res->json('data.status'))->toBe('PUBLISHING')->and($res->json('data.next_attempt_at'))->not->toBeNull();

    $this->travel(2)->minutes();
    inWs($t, fn () => app(ExecutePublish::class)->run($id));
    $job = inWs($t, fn () => PublishingJob::query()->find($id));
    expect($job->status)->toBe('FAILED')->and($job->attempts)->toBe(2)->and($job->last_error_code)->toBe('unreachable');

    // Manual retry after the site is back.
    $t['wp']->online = true;
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/retry'))->assertStatus(202)->assertJsonPath('data.status', 'PUBLISHED');
    expect($t['wp']->posts)->toHaveCount(1);
});

it('claims a job atomically: a second worker does nothing', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);
    $id = createJob($this, $t, $article, 'wp_draft')->json('data.id');
    inWs($t, fn () => PublishingJob::query()->whereKey($id)->update(['status' => 'PUBLISHING', 'next_attempt_at' => now(), 'locked_until' => now()->addMinutes(5)]));
    expect(inWs($t, fn () => app(ExecutePublish::class)->run($id)))->toBe('not_claimed')->and($t['wp']->posts)->toHaveCount(0);
});

it('detects a manual WordPress edit, never overwrites it silently, and resolves only on a human decision', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);
    $id = createJob($this, $t, $article, 'wp_draft')->json('data.id');
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/approve'))->assertOk();
    $wpId = array_key_first($t['wp']->posts);
    $t['wp']->editInWordPress($wpId, ['content' => '<p>Biên tập viên sửa tay trên WordPress.</p>']);

    $upd = createJob($this, $t, $article, 'update')->assertCreated();
    $res = $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$upd->json('data.id').'/approve'))->assertOk();
    expect($res->json('data.status'))->toBe('UPDATE_PENDING')
        ->and($t['wp']->posts[$wpId]['content'])->toBe('<p>Biên tập viên sửa tay trên WordPress.</p>');

    $conflict = inWs($t, fn () => PublishingConflict::query()->sole());
    expect($conflict->types)->toContain('manual_edit');
    $this->actingAs($t['editor'])->postJson(p6url($t['workspace'], 'publishing/conflicts/'.$conflict->id.'/resolve'), ['resolution' => 'overwrite', 'confirm' => true])->assertForbidden();
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/conflicts/'.$conflict->id.'/resolve'), ['resolution' => 'overwrite', 'confirm' => true])->assertOk();

    expect(inWs($t, fn () => PublishingJob::query()->find($upd->json('data.id'))->status))->toBe('PUBLISHED')
        ->and($t['wp']->posts[$wpId]['content'])->toContain('Tiêu chí chọn')
        ->and($t['wp']->posts)->toHaveCount(1);
});

it('keeps the WordPress version when the editor decides so, and opens a new draft on request', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);
    $id = createJob($this, $t, $article, 'wp_draft')->json('data.id');
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/approve'))->assertOk();
    $wpId = array_key_first($t['wp']->posts);
    $t['wp']->editInWordPress($wpId, ['slug' => 'slug-moi-do-bien-tap']);

    $upd = createJob($this, $t, $article, 'update')->json('data.id');
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$upd.'/approve'))->assertJsonPath('data.status', 'UPDATE_PENDING');
    $conflict = inWs($t, fn () => PublishingConflict::query()->sole());
    expect($conflict->types)->toContain('slug_changed');

    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/conflicts/'.$conflict->id.'/resolve'), ['resolution' => 'new_draft', 'confirm' => true])->assertOk();
    expect($t['wp']->posts)->toHaveCount(2)
        ->and($t['wp']->posts[$wpId]['slug'])->toBe('slug-moi-do-bien-tap');
});

it('opens a post_missing conflict when the post was deleted on WordPress', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);
    $id = createJob($this, $t, $article, 'wp_draft')->json('data.id');
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/approve'))->assertOk();
    $t['wp']->posts = [];
    $upd = createJob($this, $t, $article, 'update')->json('data.id');
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$upd.'/approve'))->assertJsonPath('data.status', 'UPDATE_PENDING');
    expect(inWs($t, fn () => PublishingConflict::query()->sole()->types))->toBe(['post_missing'])->and($t['wp']->posts)->toHaveCount(0);
});

it('schedules in the site timezone, validates the window and publishes when due', function () {
    $t = publishingTenant(['window_start_hour' => 8, 'window_end_hour' => 18]);
    $article = approvedArticle($t['workspace'], $t['site']);
    $night = now('UTC')->addDays(2)->setTime(23, 0);
    $id = createJob($this, $t, $article, 'scheduled', ['scheduled_at' => $night->toIso8601String()])->json('data.id');
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/approve'))->assertStatus(422)->assertJsonPath('error.code', 'gates_failed');

    $day = now('UTC')->addDays(2)->setTime(10, 0);
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/approve'), ['scheduled_at' => $day->toIso8601String()])
        ->assertOk()->assertJsonPath('data.status', 'SCHEDULED')->assertJsonPath('data.schedule.local_time', $day->format('Y-m-d H:i'));

    Artisan::call('sitex:publishing:dispatch');
    expect($t['wp']->posts)->toHaveCount(0);
    $this->travelTo($day->copy()->addMinute());
    Artisan::call('sitex:publishing:dispatch');
    expect($t['wp']->posts)->toHaveCount(1)->and(array_values($t['wp']->posts)[0]['status'])->toBe('publish');
});

it('marks a missed schedule instead of publishing it late', function () {
    $t = publishingTenant(['missed_grace_minutes' => 30]);
    $article = approvedArticle($t['workspace'], $t['site']);
    $at = now('UTC')->addHour()->startOfMinute();
    $id = createJob($this, $t, $article, 'scheduled', ['scheduled_at' => $at->toIso8601String()])->json('data.id');
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/approve'))->assertJsonPath('data.status', 'SCHEDULED');

    $this->travelTo($at->copy()->addHours(3));
    Artisan::call('sitex:publishing:dispatch');
    $job = inWs($t, fn () => PublishingJob::query()->find($id));
    expect($job->status)->toBe('FAILED')->and($job->last_error_code)->toBe('missed_schedule')
        ->and(inWs($t, fn () => PublishingSchedule::query()->sole()->status))->toBe('missed')
        ->and($t['wp']->posts)->toHaveCount(0);
});

it('rejects invalid transitions', function () {
    expect(PublishingStatus::can('PUBLISHED', 'PUBLISHING'))->toBeFalse()
        ->and(PublishingStatus::can('DRAFT', 'PUBLISHED'))->toBeFalse()
        ->and(PublishingStatus::can('CANCELLED', 'APPROVED'))->toBeFalse()
        ->and(PublishingStatus::can('READY_FOR_REVIEW', 'APPROVED'))->toBeTrue();

    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);
    $id = createJob($this, $t, $article, 'wp_draft')->json('data.id');
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/approve'))->assertOk();
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/cancel'))->assertStatus(409);
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$id.'/approve'))->assertStatus(409);
});

it('requires an explicit confirmation to enable auto-publish and audits it', function () {
    $t = publishingTenant();
    $url = p6url($t['workspace'], 'publishing/sites/'.$t['site']->id.'/policy');
    $this->actingAs($t['owner'])->putJson($url, ['auto_publish_enabled' => true])->assertStatus(422)->assertJsonPath('error.code', 'auto_publish_confirmation_required');
    $this->actingAs($t['owner'])->putJson($url, ['auto_publish_enabled' => true, 'confirm_auto_publish' => true])->assertOk()->assertJsonPath('data.auto_publish_enabled', true);

    $article = approvedArticle($t['workspace'], $t['site']);
    approvedArticle($t['workspace'], $t['site'], ['status' => 'NEEDS_HUMAN_REVIEW', 'title' => 'Chưa duyệt']);
    Artisan::call('sitex:publishing:auto');
    $jobs = inWs($t, fn () => PublishingJob::query()->get());
    expect($jobs)->toHaveCount(1)->and($jobs[0]->article_id)->toBe($article)->and($jobs[0]->status)->toBe('SCHEDULED')->and($jobs[0]->approval_source)->toBe('system_auto');
});

it('publishes in bulk with per-item status, partial failure and pause', function () {
    $t = publishingTenant();
    $a1 = approvedArticle($t['workspace'], $t['site'], ['title' => 'Bài một về máy lọc nước']);
    $a2 = approvedArticle($t['workspace'], $t['site'], ['title' => 'Bài hai về máy lọc nước']);
    $bad = approvedArticle($t['workspace'], $t['site'], ['status' => 'NEEDS_HUMAN_REVIEW', 'title' => 'Bài ba chưa duyệt']);

    $preview = $this->actingAs($t['editor'])->postJson(p6url($t['workspace'], 'publishing/batches/preview'), ['site_id' => $t['site']->id, 'article_ids' => [$a1, $a2, $bad], 'mode' => 'scheduled',
        'start_at' => now()->addDay()->setTime(9, 0)->toIso8601String(), 'interval_minutes' => 120])->assertOk();
    expect($preview->json('data.eligible'))->toBe(2)->and($preview->json('data.items.2.code'))->toBe('article_not_approved')
        ->and($preview->json('data.items.0.scheduled_at'))->not->toBe($preview->json('data.items.1.scheduled_at'));

    $this->actingAs($t['editor'])->postJson(p6url($t['workspace'], 'publishing/batches'), ['site_id' => $t['site']->id, 'article_ids' => [$a1], 'mode' => 'wp_draft'])->assertForbidden();
    $b = $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/batches'), ['site_id' => $t['site']->id, 'article_ids' => [$a1, $a2, $bad], 'mode' => 'wp_draft'])->assertCreated();
    expect($b->json('data.counts.PUBLISHED'))->toBe(1)->and($b->json('data.counts.PUBLISHING'))->toBe(1);

    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/batches/'.$b->json('data.id').'/pause'))->assertOk();
    $this->travel(5)->minutes();
    Artisan::call('sitex:publishing:dispatch');
    expect($t['wp']->posts)->toHaveCount(1);
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/batches/'.$b->json('data.id').'/resume'))->assertOk();
    Artisan::call('sitex:publishing:dispatch');
    expect($t['wp']->posts)->toHaveCount(2);
});

it('previews the article as a labelled simulation with the SEO snippet', function () {
    $t = publishingTenant();
    $article = approvedArticle($t['workspace'], $t['site']);
    $res = $this->actingAs($t['viewer'])->getJson(p6url($t['workspace'], 'publishing/articles/'.$article.'/preview'))->assertOk();
    expect($res->json('data.simulation'))->toBeTrue()->and($res->json('data.disclaimer'))->toContain('mô phỏng')
        ->and($res->json('data.snippet.url'))->toContain('may-loc-nuoc')->and($res->json('data.html'))->not->toContain('<h1>');
});
