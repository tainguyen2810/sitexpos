<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Queue;

/**
 * Phase 9 audit: three populated tenants (sites, research, writer, media/SEO/publishing,
 * analytics/agent fixtures). Every GET list endpoint of each tenant must return only its
 * own rows: no id of another tenant's workspace, site or article may appear anywhere.
 * (Write and cross-id access are covered route-by-route in CrossTenantIsolationTest.)
 */
it('never shows one tenant the data of the two others on any list endpoint', function () {
    Queue::fake();
    $tenants = [];
    foreach (['Alpha Shop', 'Beta Store', 'Gamma Mart'] as $i => $name) {
        $t = researchTenant($name);
        $research = researchFixture($t['workspace'], $t['site'], (string) $i);
        $writer = writerFixture($t['workspace'], $t['site'], (string) $i);
        $p6 = phase6Fixture($t['workspace'], $t['site'], $writer, (string) $i);
        phase8Fixture($t['workspace'], $t['site'], $writer, (string) $i);
        $tenants[] = $t + ['markers' => array_values(array_filter([
            $t['workspace']->id, $t['site']->id, $t['campaign']->id,
            ...collect([$research, $writer, $p6])->flatten()->map(fn ($v) => is_object($v) && isset($v->id) ? (string) $v->id : $v)
                ->filter(fn ($v) => is_string($v) && preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $v) === 1)->all(),
        ]))];
    }

    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $r) => in_array('GET', $r->methods(), true)
            && preg_match('#^api/v1/workspaces/\{workspace\}(/[a-z0-9\-/]+)?$#', $r->uri()) === 1)
        ->map(fn (Route $r) => $r->uri())->unique()->values();
    expect($routes->count())->toBeGreaterThan(30);

    $checked = 0;
    $ok = 0;
    foreach ($tenants as $i => $t) {
        $others = collect($tenants)->except($i)->flatMap(fn ($o) => $o['markers'])->all();
        foreach ($routes as $uri) {
            $url = '/'.str_replace('{workspace}', $t['workspace']->id, $uri);
            $res = $this->actingAs($t['owner'])->getJson($url);
            if ($res->status() >= 500) {
                $this->fail("$url returned {$res->status()}");
            }
            $ok += $res->status() === 200 ? 1 : 0;
            $body = (string) $res->getContent();
            foreach ($others as $id) {
                expect(str_contains($body, $id))->toBeFalse("$url leaked $id to {$t['workspace']->name}");
            }
            $checked++;
        }
    }
    expect($checked)->toBe($routes->count() * 3)
        // Most list endpoints really answered with data (not just 4xx), and each tenant has several ids to leak.
        ->and($ok)->toBeGreaterThan((int) ($checked * 0.8))
        ->and(count($tenants[0]['markers']))->toBeGreaterThan(5);
});
