<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\AnalyticsSyncRun;
use SiteXPost\Modules\Analytics\Domain\SearchPageMetric;
use SiteXPost\Modules\Analytics\Domain\SearchPerformanceDaily;
use SiteXPost\Modules\Analytics\Domain\SearchQueryMetric;
use SiteXPost\Modules\Research\Domain\SearchConsoleConnection;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => config(['sitex.analytics.backfill_days' => 5, 'sitex.analytics.max_days_per_run' => 5]));

it('connects through OAuth without ever asking for a Google password and lets only sites.manage configure it', function () {
    $t = analyticsTenant();
    $url = $this->actingAs($t['owner'])->postJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/connect"))->assertOk()->json('data.authorization_url');
    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')->toContain('webmasters.readonly')->not->toContain('password');

    $this->actingAs($t['editor'])->postJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/connect"))->assertForbidden();
    $this->actingAs($t['viewer'])->getJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/connection"))->assertOk()
        ->assertJsonPath('data.search_console.connected', false);
});

it('discovers and maps a property, then syncs every dimension idempotently with pagination and checkpoints', function () {
    $t = analyticsTenant();
    $conn = gscConnected($t['workspace'], $t['site']);
    fakeGsc(gscRows());

    $this->actingAs($t['owner'])->getJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/properties"))->assertOk()
        ->assertJsonPath('data.0.site_url', 'sc-domain:b.example');
    $this->actingAs($t['owner'])->putJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/property"), ['property_url' => 'sc-domain:other.example'])
        ->assertStatus(409)->assertJsonPath('error.code', 'gsc_property_unknown');
    $this->actingAs($t['owner'])->putJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/property"), ['property_url' => 'sc-domain:b.example'])->assertOk()
        ->assertJsonPath('data.analytics.status', 'active');

    $res = $this->actingAs($t['owner'])->postJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/sync"), ['idempotency_key' => 'k1'])->assertStatus(202);
    expect($res->json('job_run.id'))->not->toBeNull();

    [$daily, $queries, $pages, $run, $prop] = inAnalyticsTenant($t, fn () => [SearchPerformanceDaily::query()->count(), SearchQueryMetric::query()->count(), SearchPageMetric::query()->count(),
        AnalyticsSyncRun::query()->where('idempotency_key', 'like', '%:k1')->sole(), AnalyticsProperty::query()->sole()]);
    // Backfill (min 7 days) is split in runs of max_days_per_run, chained; overlapping days are upserted.
    expect($daily)->toBe(14)->and($queries)->toBe(14)->and($pages)->toBe(14)
        ->and($run->status)->toBe('completed')->and($run->checkpoint_date)->toBe($run->range_end)
        ->and($prop->last_complete_date)->toBe(now('UTC')->subDays(3)->toDateString());
    // GSC data is final after a lag: nothing newer than lag_days is requested.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'searchAnalytics') && ($r->data()['endDate'] ?? '') > now('UTC')->subDays(3)->toDateString());
    // UTM parameters are stripped: the URL maps to one normalized page.
    expect(inAnalyticsTenant($t, fn () => SearchPageMetric::query()->where('page_url', 'like', '%utm_source%')->count()))->toBe(0);

    // Same idempotency key → same run; a re-sync overlapping days upserts instead of duplicating.
    $this->actingAs($t['owner'])->postJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/sync"), ['idempotency_key' => 'k1'])->assertStatus(202)
        ->assertJsonPath('data.id', $run->id);
    $this->actingAs($t['owner'])->postJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/sync"))->assertStatus(202);
    expect(inAnalyticsTenant($t, fn () => SearchPerformanceDaily::query()->count()))->toBe(14);

    // Tokens never leave the API.
    $body = $this->actingAs($t['owner'])->getJson(anUrl($t['workspace'], 'analytics/sites'))->assertOk()->getContent();
    expect($body)->not->toContain('ya29')->not->toContain('refresh');
    expect($conn->fresh())->not->toBeNull();
});

it('backs off on quota errors, keeps the checkpoint and resumes later without duplicates', function () {
    $t = analyticsTenant();
    gscConnected($t['workspace'], $t['site']);
    $status = 429;
    fakeGsc(gscRows(), status: function () use (&$status) {
        return $status;
    });
    $this->actingAs($t['owner'])->postJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/sync"))->assertStatus(202);
    $run = inAnalyticsTenant($t, fn () => AnalyticsSyncRun::query()->sole());
    expect($run->status)->toBe('failed')->and($run->error_code)->toBe('quota_exceeded')->and($run->next_retry_at)->not->toBeNull();

    // Quota recovered; the scheduler resumes the run once its back-off passed.
    $status = 200;
    $this->travel(6)->minutes();
    Artisan::call('sitex:analytics:sync');
    expect(inAnalyticsTenant($t, fn () => AnalyticsSyncRun::query()->find($run->id)->status))->toBe('completed')
        ->and(inAnalyticsTenant($t, fn () => SearchPerformanceDaily::query()->count()))->toBe(14);
});

it('marks the connection for re-authorisation when Google revokes the token, and disconnect revokes at Google', function () {
    $t = analyticsTenant();
    $conn = gscConnected($t['workspace'], $t['site']);
    inAnalyticsTenant($t, fn () => $conn->forceFill(['token_expires_at' => now()->subMinute()])->save());
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        'oauth2.googleapis.com/revoke*' => Http::response('', 200),
        '*' => Http::response(['siteEntry' => []]),
    ]);
    $this->actingAs($t['owner'])->postJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/sync"))->assertStatus(202);
    $run = inAnalyticsTenant($t, fn () => AnalyticsSyncRun::query()->sole());
    expect($run->status)->toBe('failed')->and($run->next_retry_at)->toBeNull()
        ->and(inAnalyticsTenant($t, fn () => AnalyticsProperty::query()->sole()->status))->toBe('error');

    $this->actingAs($t['admin'])->deleteJson(anUrl($t['workspace'], "analytics/sites/{$t['site']->id}/connection"))->assertOk()->assertJsonPath('data.disconnected', true);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke'));
    expect(inAnalyticsTenant($t, fn () => SearchConsoleConnection::query()->count()))->toBe(0);
});

it('runs the daily sync once per site per day even when the scheduler fires twice', function () {
    $t = analyticsTenant();
    gscConnected($t['workspace'], $t['site']);
    fakeGsc(gscRows());
    inAnalyticsTenant($t, function () use ($t) {
        $p = new AnalyticsProperty;
        $p->forceFill(['site_id' => $t['site']->id, 'provider' => 'gsc', 'property_ref' => 'sc-domain:b.example', 'status' => 'active'])->save();
    });
    Artisan::call('sitex:analytics:sync');
    inAnalyticsTenant($t, fn () => AnalyticsProperty::query()->update(['last_sync_at' => null]));
    Artisan::call('sitex:analytics:sync');
    expect(inAnalyticsTenant($t, fn () => AnalyticsSyncRun::query()->where('idempotency_key', 'gsc:'.$t['site']->id.':daily:'.now('UTC')->toDateString())->count()))->toBe(1);
});
