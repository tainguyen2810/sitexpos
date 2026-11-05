<?php

use Database\Seeders\DemoSeeder;
use Database\Seeders\WriterDemoSeeder;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\ArticleClaim;
use SiteXPost\Modules\Writer\Domain\ContentBrief;
use SiteXPost\Modules\Writer\Domain\FactCheckResult;
use SiteXPost\Modules\Writer\Domain\ResearchDossier;
use SiteXPost\Modules\Writer\Domain\WritingBatch;
use SiteXPost\Modules\Writer\Domain\WritingPreset;

it('seeds labelled AI Writer demo data without fake verdicts or sources', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class); // idempotent

    $ws = Workspace::query()->where('name', 'Cửa hàng A (Demo)')->firstOrFail();
    $counts = app(TenantContext::class)->runAs($ws, null, fn () => [
        'articles' => Article::query()->where('is_demo', true)->where('title', 'like', '[DEMO]%')->count(),
        'briefs' => ContentBrief::query()->count(),
        'dossiers' => ResearchDossier::query()->count(),
        'claims' => ArticleClaim::query()->count(),
        'results' => FactCheckResult::query()->count(),
        'demo_results' => FactCheckResult::query()->where('reviewer_type', 'demo')->count(),
        'presets' => WritingPreset::query()->where('is_demo', true)->count(),
        'batches' => WritingBatch::query()->where('is_demo', true)->count(),
    ]);
    expect($counts)->toBe(['articles' => 20, 'briefs' => 10, 'dossiers' => 5, 'claims' => 50, 'results' => 20, 'demo_results' => 20, 'presets' => 5, 'batches' => 3]);
});

it('refuses to seed AI Writer demo data in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(WriterDemoSeeder::class)->run(app(TenantContext::class)))->toThrow(RuntimeException::class);
});
