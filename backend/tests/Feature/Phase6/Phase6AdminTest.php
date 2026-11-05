<?php

use SiteXPost\Modules\Audit\Domain\AuditLog;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;

require_once __DIR__.'/helpers.php';

/*
 * Super Admin consoles for Phase 6: media / publishing overview and limits.
 * Hidden from customers (404), gated by platform permissions, audited, and
 * the publishing kill switch really stops publishing at the policy gate.
 */

it('hides the Phase 6 admin consoles from customers and enforces platform permissions', function () {
    $t = publishingTenant();
    $support = User::factory()->platform(PlatformRole::Support, true)->create();
    $billing = User::factory()->platform(PlatformRole::BillingAdmin, true)->create();

    foreach (['/api/admin/v1/media/overview', '/api/admin/v1/media/limits', '/api/admin/v1/publishing/overview', '/api/admin/v1/publishing/limits'] as $url) {
        $this->actingAs($t['owner'])->getJson($url)->assertNotFound();
    }
    // SUPPORT manages jobs (publishing) but not AI / media settings; BILLING_ADMIN sees AI costs only.
    $this->actingAs($support)->getJson('/api/admin/v1/publishing/overview')->assertOk();
    $this->actingAs($support)->getJson('/api/admin/v1/media/limits')->assertForbidden();
    $this->actingAs($billing)->getJson('/api/admin/v1/media/overview')->assertOk();
    $this->actingAs($billing)->getJson('/api/admin/v1/publishing/overview')->assertForbidden();
    $this->actingAs($billing)->putJson('/api/admin/v1/media/limits', ['scope_key' => 'global', 'ai_images_enabled' => false])->assertForbidden();
});

it('lets a Super Admin change limits with an audit trail, and the kill switch blocks publishing', function () {
    $t = publishingTenant();
    $admin = User::factory()->platform(PlatformRole::SuperAdmin, true)->create();

    $this->actingAs($admin)->putJson('/api/admin/v1/media/limits', ['scope_key' => 'ws:'.$t['workspace']->id, 'max_ai_images_per_day' => 3, 'ai_images_enabled' => true])
        ->assertOk()->assertJsonPath('data.max_ai_images_per_day', 3);
    $this->actingAs($admin)->putJson('/api/admin/v1/media/limits', ['scope_key' => 'ws:not-a-ulid', 'ai_images_enabled' => true])->assertStatus(422);
    $this->actingAs($admin)->putJson('/api/admin/v1/publishing/limits', ['scope_key' => 'global', 'publishing_enabled' => false, 'auto_publish_allowed' => false])->assertOk();

    expect(AuditLog::query()->where('action', 'platform.media_limits_updated')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'platform.publishing_limits_updated')->exists())->toBeTrue();

    $article = approvedArticle($t['workspace'], $t['site']);
    $res = $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs'), ['article_id' => $article, 'mode' => 'wp_draft'])->assertCreated();
    expect($res->json('data.blocked_reason'))->toBe('publishing_disabled');
    $this->actingAs($t['owner'])->postJson(p6url($t['workspace'], 'publishing/jobs/'.$res->json('data.id').'/approve'))->assertStatus(422)->assertJsonPath('error.code', 'gates_failed');
    expect($t['wp']->posts)->toHaveCount(0);

    $overview = $this->actingAs($admin)->getJson('/api/admin/v1/publishing/overview')->assertOk();
    expect($overview->json('data.jobs.by_status.DRAFT'))->toBe(1);
});
