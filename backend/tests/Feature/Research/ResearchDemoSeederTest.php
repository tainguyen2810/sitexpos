<?php

use Database\Seeders\DemoSeeder;
use Database\Seeders\ResearchDemoSeeder;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Research\Domain\ContentCalendarItem;
use SiteXPost\Modules\Research\Domain\ContentIdea;
use SiteXPost\Modules\Research\Domain\Keyword;
use SiteXPost\Modules\Research\Domain\KeywordMetric;
use SiteXPost\Modules\Research\Domain\SeoCampaign;
use SiteXPost\Modules\Research\Domain\TopicCluster;
use SiteXPost\Modules\Workspace\Domain\Workspace;

it('seeds labelled demo research data without fabricated metrics', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class); // idempotent

    $ws = Workspace::query()->where('name', 'Cửa hàng A (Demo)')->firstOrFail();
    $counts = app(TenantContext::class)->runAs($ws, null, fn () => [
        'campaigns' => SeoCampaign::query()->where('is_demo', true)->count(),
        'labelled' => SeoCampaign::query()->where('name', 'like', '[DEMO]%')->count(),
        'clusters' => TopicCluster::query()->count(),
        'keywords' => Keyword::query()->count(),
        'ideas' => ContentIdea::query()->count(),
        'demo_ideas' => ContentIdea::query()->where('source', 'demo')->count(),
        'calendar' => ContentCalendarItem::query()->distinct()->count('campaign_id'),
        'metrics' => KeywordMetric::query()->count(),
    ]);

    expect($counts)->toBe(['campaigns' => 3, 'labelled' => 3, 'clusters' => 5, 'keywords' => 100, 'ideas' => 50, 'demo_ideas' => 50, 'calendar' => 1, 'metrics' => 0]);
    // Production tenants are untouched.
    expect(app(TenantContext::class)->bypass(fn () => SeoCampaign::query()->where('is_demo', false)->count()))->toBe(0);
});

it('refuses to seed demo research data in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(ResearchDemoSeeder::class)->run(app(TenantContext::class)))->toThrow(RuntimeException::class);
});
