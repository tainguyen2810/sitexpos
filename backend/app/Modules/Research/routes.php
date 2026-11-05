<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Research\Http\Controllers\Admin\AdminResearchController;
use SiteXPost\Modules\Research\Http\Controllers\CalendarController;
use SiteXPost\Modules\Research\Http\Controllers\CampaignController;
use SiteXPost\Modules\Research\Http\Controllers\ClusterController;
use SiteXPost\Modules\Research\Http\Controllers\CompetitorController;
use SiteXPost\Modules\Research\Http\Controllers\IdeaController;
use SiteXPost\Modules\Research\Http\Controllers\KeywordController;
use SiteXPost\Modules\Research\Http\Controllers\ResearchRunController;
use SiteXPost\Modules\Research\Http\Controllers\ResearchWorkspaceController;
use SiteXPost\Modules\Research\Http\Controllers\SearchConsoleController;
use SiteXPost\Modules\Research\Http\Controllers\WebsiteIntelligenceController;

// ---- Customer API (tenant) ----
// {campaign} is resolved through the fail-closed tenant scope; every child
// parameter uses an explicit `:id` field so Laravel scopes it to the campaign
// through the matching SeoCampaign relation (keywords, ideas, ...).
Route::prefix('api/v1/workspaces/{workspace}/research')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api', 'sitex.tenant', 'throttle:workspace'])
    ->name('research.')
    ->group(function (): void {
        Route::get('usage', [ResearchWorkspaceController::class, 'usage'])->name('usage');
        Route::get('notifications', [ResearchWorkspaceController::class, 'notifications'])->name('notifications.index');
        Route::post('notifications/{notification}/read', [ResearchWorkspaceController::class, 'markRead'])->name('notifications.read');
        Route::get('calendar', [CalendarController::class, 'workspace'])->name('calendar.workspace');

        Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::post('campaigns', [CampaignController::class, 'store'])->middleware('sitex.verified')->name('campaigns.store');

        Route::prefix('campaigns/{campaign}')->group(function (): void {
            Route::get('/', [CampaignController::class, 'show'])->name('campaigns.show');
            Route::patch('/', [CampaignController::class, 'update'])->name('campaigns.update');
            Route::delete('/', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
            Route::get('overview', [CampaignController::class, 'overview'])->name('campaigns.overview');
            Route::get('settings', [CampaignController::class, 'settings'])->name('campaigns.settings');
            Route::patch('settings', [CampaignController::class, 'updateSettings'])->name('campaigns.settings.update');
            Route::get('schedule', [CampaignController::class, 'schedule'])->name('campaigns.schedule');
            Route::put('schedule', [CampaignController::class, 'updateSchedule'])->name('campaigns.schedule.update');

            Route::get('intelligence', [WebsiteIntelligenceController::class, 'show'])->name('intelligence.show');
            Route::post('intelligence/knowledge', [WebsiteIntelligenceController::class, 'store'])->name('knowledge.store');
            Route::patch('intelligence/knowledge/{knowledge:id}', [WebsiteIntelligenceController::class, 'update'])->name('knowledge.update');
            Route::delete('intelligence/knowledge/{knowledge:id}', [WebsiteIntelligenceController::class, 'destroy'])->name('knowledge.destroy');

            Route::get('runs', [ResearchRunController::class, 'index'])->name('runs.index');
            Route::post('runs', [ResearchRunController::class, 'store'])->middleware(['sitex.verified', 'throttle:research-runs'])->name('runs.store');
            Route::get('runs/{researchRun:id}', [ResearchRunController::class, 'show'])->name('runs.show');
            Route::get('runs/{researchRun:id}/snapshots', [ResearchRunController::class, 'snapshots'])->name('runs.snapshots');
            Route::post('runs/{researchRun:id}/cancel', [ResearchRunController::class, 'cancel'])->name('runs.cancel');
            Route::post('runs/{researchRun:id}/retry', [ResearchRunController::class, 'retry'])->middleware('throttle:research-runs')->name('runs.retry');

            Route::get('keywords', [KeywordController::class, 'index'])->name('keywords.index');
            Route::post('keywords', [KeywordController::class, 'store'])->name('keywords.store');
            Route::post('keywords/bulk', [KeywordController::class, 'bulk'])->name('keywords.bulk');
            Route::patch('keywords/{keyword:id}', [KeywordController::class, 'update'])->name('keywords.update');

            Route::get('ideas', [IdeaController::class, 'index'])->name('ideas.index');
            Route::get('ideas/{idea:id}', [IdeaController::class, 'show'])->name('ideas.show');
            Route::patch('ideas/{idea:id}', [IdeaController::class, 'update'])->name('ideas.update');
            Route::post('ideas/{idea:id}/brief', [IdeaController::class, 'brief'])->name('ideas.brief');

            Route::get('clusters', [ClusterController::class, 'index'])->name('clusters.index');
            Route::get('clusters/{cluster:id}', [ClusterController::class, 'show'])->name('clusters.show');
            Route::patch('clusters/{cluster:id}', [ClusterController::class, 'update'])->name('clusters.update');
            Route::get('gaps', [ClusterController::class, 'gaps'])->name('gaps.index');
            Route::patch('gaps/{gap:id}', [ClusterController::class, 'updateGap'])->name('gaps.update');
            Route::get('internal-links', [ClusterController::class, 'links'])->name('links.index');

            Route::get('competitors', [CompetitorController::class, 'index'])->name('competitors.index');
            Route::post('competitors', [CompetitorController::class, 'store'])->name('competitors.store');
            Route::patch('competitors/{competitor:id}', [CompetitorController::class, 'update'])->name('competitors.update');
            Route::delete('competitors/{competitor:id}', [CompetitorController::class, 'destroy'])->name('competitors.destroy');
            Route::get('competitors/{competitor:id}/findings', [CompetitorController::class, 'findings'])->name('competitors.findings');

            Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
            Route::post('calendar', [CalendarController::class, 'store'])->name('calendar.store');
            Route::post('calendar/auto-plan', [CalendarController::class, 'autoPlan'])->name('calendar.auto-plan');
            Route::patch('calendar/{calendarItem:id}', [CalendarController::class, 'update'])->name('calendar.update');
            Route::delete('calendar/{calendarItem:id}', [CalendarController::class, 'destroy'])->name('calendar.destroy');

            Route::get('search-console', [SearchConsoleController::class, 'show'])->name('search-console.show');
            Route::post('search-console/connect', [SearchConsoleController::class, 'connect'])->name('search-console.connect');
            Route::get('search-console/properties', [SearchConsoleController::class, 'properties'])->name('search-console.properties');
            Route::put('search-console/property', [SearchConsoleController::class, 'setProperty'])->name('search-console.property');
            Route::delete('search-console', [SearchConsoleController::class, 'disconnect'])->name('search-console.disconnect');
        });
    });

// ---- Google OAuth callback (browser redirect; state is encrypted, single-use) ----
Route::get('api/v1/oauth/google/callback', [SearchConsoleController::class, 'callback'])
    ->middleware('throttle:oauth-callback')
    ->name('research.search-console.callback');

// ---- Admin API (Super Admin; cost views also BILLING_ADMIN; monitor also SUPPORT) ----
$manage = 'sitex.platform:platform.ai.manage';
$costs = 'sitex.platform:platform.ai.view_costs';
$jobs = 'sitex.platform:platform.jobs.manage';

Route::prefix('api/admin/v1/research')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'sitex.platform', 'throttle:api'])
    ->name('admin.research.')
    ->group(function () use ($manage, $costs, $jobs): void {
        Route::get('sources', [AdminResearchController::class, 'sources'])->middleware($manage)->name('sources.index');
        Route::patch('sources/{source}', [AdminResearchController::class, 'updateSource'])->middleware($manage)->where('source', '[a-z_]{2,32}')->name('sources.update');
        Route::get('settings', [AdminResearchController::class, 'globalSettings'])->middleware($manage)->name('settings.show');
        Route::patch('settings', [AdminResearchController::class, 'updateGlobalSettings'])->middleware($manage)->name('settings.update');
        Route::get('limits', [AdminResearchController::class, 'limits'])->middleware($manage)->name('limits.index');
        Route::put('limits', [AdminResearchController::class, 'upsertLimit'])->middleware($manage)->name('limits.upsert');
        Route::delete('limits/{limit}', [AdminResearchController::class, 'destroyLimit'])->middleware($manage)->name('limits.destroy');
        Route::get('runs', [AdminResearchController::class, 'runs'])->middleware($jobs)->name('runs.index');
        Route::get('failures', [AdminResearchController::class, 'failures'])->middleware($jobs)->name('failures');
        Route::get('costs', [AdminResearchController::class, 'costs'])->middleware($costs)->name('costs');
        Route::get('usage', [AdminResearchController::class, 'usage'])->middleware($costs)->name('usage');
    });
