<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use SiteXPost\Modules\Research\Domain\ResearchSourceSetting;
use SiteXPost\Modules\Writer\Domain\EvidenceSource;
use SiteXPost\Modules\Writer\Domain\ResearchDossier;
use SiteXPost\Modules\Writer\Domain\SourceRule;
use SiteXPost\Modules\Writer\Support\WriterSettings;

require_once __DIR__.'/helpers.php';

/*
 * v1.0.11: source filter ("Nguồn tin cậy") + Google top 10 as extra source
 * candidates. Blocked domains are never fetched or used; preferred domains
 * get a reliability bonus and are suggested to the AI first; Google results
 * (DataForSEO) are candidates only and go through the same filter.
 */

function dataForSeo(): void
{
    $s = new ResearchSourceSetting;
    $s->forceFill(['source' => 'dataforseo', 'is_enabled' => true, 'secret_encrypted' => 'login@example.com:pa55word'])->save();
}

it('lets owners and admins manage the source filter and everyone read it', function () {
    $t = writerTenant();

    $this->actingAs($t['owner'])->postJson(writerUrl($t, 'source-rules'), ['domain' => 'https://www.Content-Farm.example/abc', 'mode' => 'block', 'note' => 'Copy bài'])
        ->assertCreated()->assertJsonPath('data.domain', 'content-farm.example')->assertJsonPath('data.mode', 'block');
    // Same domain again switches the mode (one row per domain).
    $this->actingAs($t['admin'])->postJson(writerUrl($t, 'source-rules'), ['domain' => 'content-farm.example', 'mode' => 'prefer'])
        ->assertOk()->assertJsonPath('data.mode', 'prefer');
    $this->actingAs($t['owner'])->postJson(writerUrl($t, 'source-rules'), ['domain' => 'not a domain', 'mode' => 'block'])->assertStatus(422);
    $this->actingAs($t['owner'])->postJson(writerUrl($t, 'source-rules'), ['domain' => 'ok.example', 'mode' => 'maybe'])->assertStatus(422);

    $this->actingAs($t['editor'])->postJson(writerUrl($t, 'source-rules'), ['domain' => 'x.example', 'mode' => 'block'])->assertForbidden();
    $this->actingAs($t['viewer'])->postJson(writerUrl($t, 'source-rules'), ['domain' => 'x.example', 'mode' => 'block'])->assertForbidden();

    $list = $this->actingAs($t['viewer'])->getJson(writerUrl($t, 'source-rules'))->assertOk();
    expect($list->json('data'))->toHaveCount(1)->and($list->json('meta.can_manage'))->toBeFalse();
    expect($this->actingAs($t['owner'])->getJson(writerUrl($t, 'source-rules'))->json('meta.can_manage'))->toBeTrue();

    $id = $list->json('data.0.id');
    $this->actingAs($t['editor'])->deleteJson(writerUrl($t, "source-rules/{$id}"))->assertForbidden();
    $this->actingAs($t['owner'])->deleteJson(writerUrl($t, "source-rules/{$id}"))->assertNoContent();
    expect(inResearchTenant($t, fn () => SourceRule::query()->count()))->toBe(0);
});

it('never fetches a blocked source and tells the AI which domains to prefer and avoid', function () {
    $t = writerTenant();
    $this->actingAs($t['owner'])->postJson(writerUrl($t, 'source-rules'), ['domain' => 'blocked.example', 'mode' => 'block'])->assertCreated();
    $this->actingAs($t['owner'])->postJson(writerUrl($t, 'source-rules'), ['domain' => 'intel.example', 'mode' => 'prefer'])->assertCreated();
    $t['ai']->on('source_candidates', fn () => ['urls' => [['url' => 'https://news.blocked.example/ax210', 'reason' => 'x']]]);

    $id = createArticle($t, ['source_urls' => [AX210_URL, AX200_URL, 'https://blocked.example/page'], 'ai_source_discovery' => true])->assertCreated()->json('data.id');

    $sources = inResearchTenant($t, fn () => EvidenceSource::query()->whereIn('research_dossier_id', ResearchDossier::query()->where('article_id', $id)->pluck('id'))->get());
    $blocked = $sources->firstWhere('url', 'https://blocked.example/page');
    expect($blocked->retrieval_status)->toBe('blocked_policy')->and($blocked->error_code)->toBe('source_blocked')
        ->and($sources->pluck('url')->all())->not->toContain('https://news.blocked.example/ax210');
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'blocked.example'));

    $intel = $sources->firstWhere('url', AX210_URL);
    expect($intel->reliability['indicators'])->toContain('preferred_source');

    $prompt = collect($t['ai']->calls)->firstWhere('schema', 'source_candidates')['prompt'];
    expect($prompt)->toContain('"preferred_domains":["intel.example"]')->toContain('blocked.example');
    expect(inResearchTenant($t, fn () => ResearchDossier::query()->where('article_id', $id)->first()->warnings))->toContain('blocked_sources:2');
});

it('applies the platform block list from the Super Admin', function () {
    $t = writerTenant();
    app(WriterSettings::class)->save(null, ['blocked_source_domains' => ['intel.example']], null);

    $id = createArticle($t)->assertCreated()->json('data.id');

    $statuses = inResearchTenant($t, fn () => EvidenceSource::query()->whereIn('research_dossier_id', ResearchDossier::query()->where('article_id', $id)->pluck('id'))->pluck('retrieval_status')->unique()->all());
    expect($statuses)->toBe(['blocked_policy']);
    expect($this->actingAs($t['viewer'])->getJson(writerUrl($t, 'source-rules'))->json('meta.platform_blocked'))->toBe(['intel.example']);
});

it('adds Google top results as candidates when DataForSEO is configured', function () {
    $serp = [];
    $t = writerTenant(['http' => function (HttpRequest $r) use (&$serp) {
        if (! str_starts_with($r->url(), 'https://api.dataforseo.com/')) {
            return null;
        }
        $serp[] = $r->data();

        return Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [['items' => [
            ['type' => 'paid', 'rank_group' => 1, 'url' => 'https://ads.example/x', 'title' => 'Ad'],
            ['type' => 'organic', 'rank_group' => 1, 'url' => AX210_URL, 'title' => 'AX210', 'domain' => 'www.intel.example'],
            ['type' => 'organic', 'rank_group' => 2, 'url' => 'https://spam.example/ax210', 'title' => 'Spam', 'domain' => 'spam.example'],
            ['type' => 'organic', 'rank_group' => 3, 'url' => 'https://wifi-store.example.vn/ax210/', 'title' => 'Own', 'domain' => 'wifi-store.example.vn'],
        ]]]]]]);
    }]);
    dataForSeo();
    $this->actingAs($t['owner'])->postJson(writerUrl($t, 'source-rules'), ['domain' => 'spam.example', 'mode' => 'block'])->assertCreated();

    $id = createArticle($t, ['source_urls' => [], 'ai_source_discovery' => true])->assertCreated()->json('data.id');

    expect($serp)->toHaveCount(1)
        ->and($serp[0][0]['keyword'])->toBe('intel ax210 wifi 6e')
        ->and($serp[0][0]['location_code'])->toBe(2704);
    $sources = inResearchTenant($t, fn () => EvidenceSource::query()->whereIn('research_dossier_id', ResearchDossier::query()->where('article_id', $id)->pluck('id'))->get());
    $viaSerp = $sources->where('discovered_via', 'serp')->pluck('url')->all();
    expect($viaSerp)->toBe([AX210_URL]); // ads, blocked and own-site results skipped
});

it('skips the Google channel silently when DataForSEO is not configured', function () {
    $t = writerTenant();

    $id = createArticle($t, ['ai_source_discovery' => true])->assertCreated()->json('data.id');

    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'dataforseo'));
    expect(inResearchTenant($t, fn () => ResearchDossier::query()->where('article_id', $id)->first()->warnings ?? []))->not->toContain('serp_failed:not_configured');
});
