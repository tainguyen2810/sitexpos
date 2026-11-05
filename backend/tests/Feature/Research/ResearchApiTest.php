<?php

use Illuminate\Support\Facades\Http;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Research\Domain\CompetitorFinding;
use SiteXPost\Modules\Research\Domain\ContentCalendarItem;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\ResearchSourceSetting;
use SiteXPost\Modules\Research\Domain\WebsiteKnowledge;

/**
 * Phase 4 API rules: competitors (SSRF, robots, no copying), knowledge the
 * AI never overwrites, calendar never creates content, briefs, admin
 * secrets never exposed.
 */
function ranResearch(array $t): array
{
    test()->actingAs($t['owner'])->postJson(researchUrl($t, 'runs'))->assertStatus(202);

    return $t;
}

it('rejects competitor URLs that point to private networks or the own site', function () {
    $t = researchTenant();

    $this->actingAs($t['owner'])->postJson(researchUrl($t, 'competitors'), ['url' => 'http://127.0.0.1/admin'])
        ->assertStatus(422)->assertJsonValidationErrors(['url'], 'error.details');
    $this->actingAs($t['owner'])->postJson(researchUrl($t, 'competitors'), ['url' => 'http://169.254.169.254/latest/meta-data'])
        ->assertStatus(422);
    $this->actingAs($t['owner'])->postJson(researchUrl($t, 'competitors'), ['url' => 'https://www.tech-store.example.vn/'])
        ->assertStatus(422);
    $this->actingAs($t['owner'])->postJson(researchUrl($t, 'competitors'), ['url' => 'https://rival.example.vn/'])->assertCreated();
    $this->actingAs($t['owner'])->postJson(researchUrl($t, 'competitors'), ['url' => 'https://www.rival.example.vn/blog'])->assertStatus(422);
});

it('collects competitor structure with URL and time, honours robots, never stores article bodies', function () {
    $rival = 'https://rival.example.vn';
    $t = researchTenant('Tech Store', ['pages' => [
        $rival.'/robots.txt' => "User-agent: *\nDisallow: /private/\nSitemap: {$rival}/sitemap.xml\n",
        $rival.'/' => '<html><head><title>Rival - Tai nghe gaming</title></head><body><h1>Tai nghe gaming giá tốt</h1><nav><a href="/danh-muc/tai-nghe/">Tai nghe</a></nav></body></html>',
        $rival.'/sitemap.xml' => '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'.$rival.'/cach-chon-tai-nghe-gaming/</loc></url><url><loc>'.$rival.'/private/secret/</loc></url></urlset>',
        $rival.'/cach-chon-tai-nghe-gaming/' => '<html><head><title>Cách chọn tai nghe gaming cho game thủ</title></head><body><h1>Cách chọn tai nghe gaming cho game thủ</h1><h2>Tai nghe gaming có cần mic rời không?</h2><p>'.str_repeat('Nội dung độc quyền của đối thủ. ', 50).'</p></body></html>',
    ]]);
    $this->actingAs($t['owner'])->postJson(researchUrl($t, 'competitors'), ['url' => $rival])->assertCreated();

    ranResearch($t);

    $findings = inResearchTenant($t, fn () => CompetitorFinding::query()->get());
    expect($findings)->not->toBeEmpty()
        ->and($findings->pluck('url')->filter()->every(fn ($u) => str_starts_with($u, $rival)))->toBeTrue()
        ->and($findings->every(fn ($f) => $f->fetched_at !== null))->toBeTrue()
        ->and($findings->pluck('url')->contains(fn ($u) => str_contains((string) $u, '/private/')))->toBeFalse();
    // Only titles/headings are kept, never the article text.
    $stored = json_encode($findings->toArray());
    expect($stored)->not->toContain('Nội dung độc quyền');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/private/'));
});

it('keeps user decisions on website knowledge when research runs again', function () {
    $t = ranResearch(researchTenant());
    $intel = $this->actingAs($t['owner'])->getJson(researchUrl($t, 'intelligence'))->assertOk()->json('data');
    expect($intel['profile'])->not->toBeNull()->and($intel['knowledge'])->not->toBeEmpty();

    $first = $intel['knowledge'][0];
    $this->actingAs($t['editor'])->deleteJson(researchUrl($t, "intelligence/knowledge/{$first['id']}"))->assertNoContent();
    $added = $this->actingAs($t['editor'])->postJson(researchUrl($t, 'intelligence/knowledge'), ['kind' => 'audience', 'value' => 'Game thủ chuyên nghiệp'])
        ->assertCreated()->json('data');

    ranResearch($t);

    $rows = inResearchTenant($t, fn () => WebsiteKnowledge::query()->get()->keyBy('id'));
    expect($rows[$first['id']]->status)->toBe('rejected')
        ->and($rows[$added['id']]->status)->toBe('confirmed')
        ->and($rows[$added['id']]->source)->toBe('user');
});

it('plans the calendar only on request and never creates or publishes content', function () {
    $t = ranResearch(researchTenant());
    $idea = $this->actingAs($t['owner'])->getJson(researchUrl($t, 'ideas'))->json('data.0');
    $date = now()->addDays(3)->toDateString();

    $item = $this->actingAs($t['editor'])->postJson(researchUrl($t, 'calendar'), ['content_idea_id' => $idea['id'], 'scheduled_for' => $date])
        ->assertCreated()->json('data');
    $this->actingAs($t['editor'])->postJson(researchUrl($t, 'calendar'), ['content_idea_id' => $idea['id'], 'scheduled_for' => $date])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_scheduled');

    // Drag & drop = PATCH the date; pause; approve.
    $moved = now()->addDays(10)->toDateString();
    $this->actingAs($t['editor'])->patchJson(researchUrl($t, "calendar/{$item['id']}"), ['scheduled_for' => $moved, 'position' => 2])
        ->assertOk()->assertJsonPath('data.scheduled_for', $moved);
    $this->actingAs($t['editor'])->patchJson(researchUrl($t, "calendar/{$item['id']}"), ['status' => 'paused'])->assertOk()->assertJsonPath('data.status', 'paused');

    $plan = $this->actingAs($t['editor'])->postJson(researchUrl($t, 'calendar/auto-plan'))->assertOk();
    expect($plan->json('data.scheduled'))->toBeGreaterThanOrEqual(0);
    $proposed = inResearchTenant($t, fn () => ContentCalendarItem::query()->where('source', '!=', 'user')->pluck('status')->unique()->all());
    expect(array_diff($proposed, ['proposed']))->toBe([]);

    // Rejecting the idea cancels its slot.
    $this->actingAs($t['editor'])->patchJson(researchUrl($t, "ideas/{$idea['id']}"), ['status' => 'rejected'])->assertOk();
    expect(inResearchTenant($t, fn () => ContentCalendarItem::query()->find($item['id'])->status))->toBe('canceled');

    // Nothing was sent to WordPress (no publish endpoint was called).
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/wp-json/'));
    $this->actingAs($t['owner'])->getJson("/api/v1/workspaces/{$t['workspace']->id}/research/calendar?from=".now()->toDateString().'&to='.now()->addMonth()->toDateString())->assertOk();
});

it('builds a content brief from stored research without calling AI', function () {
    $t = ranResearch(researchTenant());
    $idea = $this->actingAs($t['owner'])->getJson(researchUrl($t, 'ideas'))->json('data.0');

    $brief = $this->actingAs($t['editor'])->postJson(researchUrl($t, "ideas/{$idea['id']}/brief"))->assertOk()->json('data');

    expect($brief['status'])->toBe('briefed')
        ->and($brief['brief']['primary_keyword'])->toBe($idea['primary_keyword']['keyword'])
        ->and($brief['brief']['rules'])->toHaveCount(3);
    expect(inResearchTenant($t, fn () => ContentIdea::query()->find($idea['id'])->status))->toBe('briefed');
});

it('never shows provider secrets to customers or in the admin API', function () {
    $t = researchTenant();
    $admin = User::factory()->platform(PlatformRole::SuperAdmin, true)->create();
    $secret = 'login@example.com:sup3r-s3cret-pass';

    $this->actingAs($admin)->patchJson('/api/admin/v1/research/sources/dataforseo', ['is_enabled' => true, 'secret' => $secret])
        ->assertOk()->assertJsonPath('data.has_secret', true)->assertJsonMissingPath('data.secret');
    expect(ResearchSourceSetting::query()->where('source', 'dataforseo')->value('secret_encrypted'))->toBe($secret);

    foreach (['/api/admin/v1/research/sources', '/api/admin/v1/research/settings', '/api/admin/v1/research/limits', '/api/admin/v1/research/runs',
        '/api/admin/v1/research/costs', '/api/admin/v1/research/failures', '/api/admin/v1/research/usage'] as $url) {
        $body = $this->actingAs($admin)->getJson($url)->assertOk()->getContent();
        expect($body)->not->toContain('sup3r-s3cret-pass');
    }
    $usage = $this->actingAs($t['owner'])->getJson("/api/v1/workspaces/{$t['workspace']->id}/research/usage")->assertOk()->getContent();
    expect($usage)->not->toContain('sup3r-s3cret-pass');

    // Customers cannot reach the admin API.
    $this->actingAs($t['owner'])->getJson('/api/admin/v1/research/sources')->assertNotFound();
});

it('lets Super Admin set tenant limits that the customer API enforces', function () {
    $t = researchTenant();
    $admin = User::factory()->platform(PlatformRole::SuperAdmin, true)->create();

    $this->actingAs($admin)->putJson('/api/admin/v1/research/limits', [
        'scope' => 'workspace', 'workspace_id' => $t['workspace']->id, 'max_campaigns' => 1, 'max_runs_per_month' => 5, 'max_ideas_per_run' => 10,
        'max_competitors_per_campaign' => 1, 'max_calendar_items_per_month' => 10, 'max_concurrent_runs' => 1, 'max_ai_cost_per_run_micro_usd' => 100000,
    ])->assertCreated();

    $this->actingAs($t['owner'])->postJson(researchUrl($t, 'competitors'), ['url' => 'https://a.example.vn'])->assertCreated();
    $this->actingAs($t['owner'])->postJson(researchUrl($t, 'competitors'), ['url' => 'https://b.example.vn'])->assertStatus(429);
    $this->actingAs($t['owner'])->postJson("/api/v1/workspaces/{$t['workspace']->id}/research/campaigns", [
        'site_id' => $t['site']->id, 'name' => 'Thứ hai', 'industry' => 'Đồ công nghệ', 'market' => 'VN', 'language' => 'vi',
    ])->assertStatus(429)->assertJsonPath('error.code', 'research_quota_exceeded');
    $this->actingAs($t['owner'])->patchJson(researchUrl($t, 'settings'), ['ideas_per_run' => 30])->assertStatus(422);
});
