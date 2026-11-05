<?php

use Database\Seeders\DemoSeeder;
use Database\Seeders\Phase6DemoSeeder;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Media\Domain\ImageGenerationJob;
use SiteXPost\Modules\Media\Domain\MediaAsset;
use SiteXPost\Modules\Publishing\Domain\PublishingConflict;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\PublishingSchedule;
use SiteXPost\Modules\Seo\Domain\SeoAuditRun;
use SiteXPost\Modules\Workspace\Domain\Workspace;

it('seeds labelled Phase 6 demo data (media, AI images, audits, jobs, schedules, conflicts)', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class); // idempotent

    $ws = Workspace::query()->where('name', 'Cửa hàng A (Demo)')->firstOrFail();
    $counts = app(TenantContext::class)->runAs($ws, null, fn () => [
        'assets' => MediaAsset::query()->where('is_demo', true)->count(),
        'ai_assets' => MediaAsset::query()->where('is_demo', true)->where('is_ai_generated', true)->where('license', 'ai_generated')->count(),
        'ai_jobs' => ImageGenerationJob::query()->where('is_demo', true)->count(),
        'audits' => SeoAuditRun::query()->where('is_demo', true)->count(),
        'jobs' => PublishingJob::query()->where('is_demo', true)->count(),
        'scheduled' => PublishingJob::query()->where('is_demo', true)->where('status', 'SCHEDULED')->count(),
        'schedules' => PublishingSchedule::query()->count(),
        'conflicts' => PublishingConflict::query()->where('is_demo', true)->where('status', 'open')->count(),
    ]);
    expect($counts)->toBe(['assets' => 30, 'ai_assets' => 10, 'ai_jobs' => 10, 'audits' => 10, 'jobs' => 20, 'scheduled' => 5, 'schedules' => 5, 'conflicts' => 3]);

    // AI images are always labelled and never product photos.
    $ai = app(TenantContext::class)->runAs($ws, null, fn () => MediaAsset::query()->where('is_ai_generated', true)->get());
    expect($ai->every(fn ($a) => $a->source_type === 'ai' && str_contains((string) $a->caption, 'AI') && ($a->metadata['product'] ?? null) === null))->toBeTrue();
});

it('refuses to seed Phase 6 demo data in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(Phase6DemoSeeder::class)->run(app(TenantContext::class)))->toThrow(RuntimeException::class);
});
