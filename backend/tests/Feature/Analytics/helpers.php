<?php

use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use Tests\Support\FakeWordPress;

function anUrl(Workspace $ws, string $path): string
{
    return '/api/v1/workspaces/'.$ws->id.'/'.ltrim($path, '/');
}

/**
 * Tenant with roles + a connected site (FakeWordPress at https://b.example).
 *
 * @return array<string, mixed>
 */
function analyticsTenant(string $name = 'Analytics'): array
{
    fakePublicDns();
    config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
    $t = workspaceWithRoles($name);
    $wp = new FakeWordPress('https://b.example');
    $site = connectedSite($t['workspace'], $wp, 'Blog B');

    return $t + ['site' => $site, 'wp' => $wp];
}

function inAnalyticsTenant(array $t, callable $fn): mixed
{
    return app(TenantContext::class)->runAs($t['workspace'], null, $fn);
}

/**
 * GSC fake rows for N days: two pages / two queries per day.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function gscRows(): array
{
    return [
        'device,country' => [['keys' => ['MOBILE', 'vnm'], 'clicks' => 30, 'impressions' => 900, 'ctr' => 0.033, 'position' => 6.4],
            ['keys' => ['DESKTOP', 'vnm'], 'clicks' => 10, 'impressions' => 300, 'ctr' => 0.033, 'position' => 7.1]],
        'query' => [['keys' => ['máy lọc nước'], 'clicks' => 25, 'impressions' => 800, 'ctr' => 0.031, 'position' => 5.2],
            ['keys' => ['lõi lọc nước'], 'clicks' => 15, 'impressions' => 400, 'ctr' => 0.037, 'position' => 8.9]],
        'page' => [['keys' => ['https://b.example/may-loc-nuoc/'], 'clicks' => 28, 'impressions' => 850, 'ctr' => 0.033, 'position' => 5.5],
            ['keys' => ['https://b.example/loi-loc/?utm_source=x'], 'clicks' => 12, 'impressions' => 350, 'ctr' => 0.034, 'position' => 9.0]],
        'query,page' => [['keys' => ['máy lọc nước', 'https://b.example/may-loc-nuoc/'], 'clicks' => 500, 'impressions' => 15000, 'ctr' => 0.033, 'position' => 5.2]],
    ];
}
