<?php

use Database\Seeders\DemoSeeder;
use Database\Seeders\Phase8DemoSeeder;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Agent\Domain\AgentRun;
use SiteXPost\Modules\Analytics\Domain\AnalyticsProperty;
use SiteXPost\Modules\Analytics\Domain\ContentRefreshJob;
use SiteXPost\Modules\Analytics\Domain\SeoExperiment;
use SiteXPost\Modules\Analytics\Domain\SeoOpportunity;
use SiteXPost\Modules\Analytics\Domain\SeoRecommendation;
use SiteXPost\Modules\Analytics\Domain\SeoReport;
use SiteXPost\Modules\Workspace\Domain\Workspace;

it('seeds labelled Phase 8 demo data with the required volumes, analysed by the real engine', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class); // idempotent

    $ws = Workspace::query()->where('name', 'Cửa hàng A (Demo)')->firstOrFail();
    $c = app(TenantContext::class)->runAs($ws, null, fn () => [
        'properties' => AnalyticsProperty::query()->where('is_demo', true)->count(),
        'months' => (int) ceil(DB::table('search_performance_daily')->where('workspace_id', $ws->id)->distinct()->count('date') / 30),
        'articles' => DB::table('analytics_url_mappings')->where('workspace_id', $ws->id)->whereNotNull('article_id')->distinct()->count('article_id'),
        'queries' => DB::table('search_query_metrics')->where('workspace_id', $ws->id)->distinct()->count('query_hash'),
        'opportunities' => SeoOpportunity::query()->where('is_demo', true)->count(),
        'recommendations' => SeoRecommendation::query()->where('is_demo', true)->count(),
        'runs' => AgentRun::query()->where('is_demo', true)->count(),
        'refresh' => ContentRefreshJob::query()->where('is_demo', true)->count(),
        'experiments' => SeoExperiment::query()->where('is_demo', true)->count(),
        'reports' => SeoReport::query()->where('is_demo', true)->where('period_type', 'monthly')->where('status', 'ready')->count(),
        'unlabelled_metrics' => DB::table('search_page_metrics')->where('workspace_id', $ws->id)->where('is_demo', false)->count(),
        'ab' => SeoExperiment::query()->where('method', '!=', 'before_after')->count(),
    ]);
    expect($c['properties'])->toBe(3)
        ->and($c['months'])->toBeGreaterThanOrEqual(12)
        ->and($c['articles'])->toBeGreaterThanOrEqual(100)
        ->and($c['queries'])->toBeGreaterThanOrEqual(500)
        ->and($c['opportunities'])->toBeGreaterThanOrEqual(50)
        ->and($c['recommendations'])->toBeGreaterThanOrEqual(30)
        ->and($c['runs'])->toBe(20)
        ->and($c['refresh'])->toBe(10)
        ->and($c['experiments'])->toBe(5)
        ->and($c['reports'])->toBe(6)
        ->and($c['unlabelled_metrics'])->toBe(0)
        ->and($c['ab'])->toBe(0);

    // Every opportunity comes from the rule engine with evidence; at most one active run per site.
    $opps = app(TenantContext::class)->runAs($ws, null, fn () => SeoOpportunity::query()->where('is_demo', true)->get());
    expect($opps->every(fn ($o) => $o->evidence !== [] && $o->detection_rule !== '' && $o->confidence > 0))->toBeTrue();
    $active = app(TenantContext::class)->runAs($ws, null, fn () => AgentRun::query()->whereNotNull('active_key')->get()->groupBy('site_id'));
    expect($active->every(fn ($runs) => $runs->count() === 1))->toBeTrue();
});

it('refuses to seed Phase 8 demo data in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(Phase8DemoSeeder::class)->run(app(TenantContext::class)))->toThrow(RuntimeException::class);
});
