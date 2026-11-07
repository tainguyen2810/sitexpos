<?php

use Illuminate\Support\Facades\Route;
use SiteXPost\Modules\Writer\Http\Controllers\Admin\AdminWriterController;
use SiteXPost\Modules\Writer\Http\Controllers\ArticleContentController;
use SiteXPost\Modules\Writer\Http\Controllers\ArticleController;
use SiteXPost\Modules\Writer\Http\Controllers\ArticleResearchController;
use SiteXPost\Modules\Writer\Http\Controllers\ArticleReviewController;
use SiteXPost\Modules\Writer\Http\Controllers\BatchController;
use SiteXPost\Modules\Writer\Http\Controllers\PresetController;
use SiteXPost\Modules\Writer\Http\Controllers\SourceRuleController;
use SiteXPost\Modules\Writer\Http\Controllers\WriterWorkspaceController;

// ---- Customer API (tenant) ----
// {article}, {preset}, {batch} resolve through the fail-closed tenant scope;
// child parameters use `:id` so Laravel scopes them to the article through
// the matching Article relation (versions, claims, revisions).
Route::prefix('api/v1/workspaces/{workspace}/writer')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'throttle:api', 'sitex.tenant', 'throttle:workspace'])
    ->name('writer.')
    ->group(function (): void {
        Route::get('overview', [WriterWorkspaceController::class, 'overview'])->name('overview');
        Route::get('options', [WriterWorkspaceController::class, 'options'])->name('options');
        Route::get('ideas', [WriterWorkspaceController::class, 'ideas'])->name('ideas');
        Route::get('estimate', [WriterWorkspaceController::class, 'estimate'])->name('estimate');
        Route::get('history', [WriterWorkspaceController::class, 'history'])->name('history');

        Route::get('source-rules', [SourceRuleController::class, 'index'])->name('source_rules.index');
        Route::post('source-rules', [SourceRuleController::class, 'store'])->name('source_rules.store');
        Route::delete('source-rules/{sourceRule}', [SourceRuleController::class, 'destroy'])->name('source_rules.destroy');

        Route::get('presets', [PresetController::class, 'index'])->name('presets.index');
        Route::post('presets', [PresetController::class, 'store'])->name('presets.store');
        Route::get('presets/{preset}', [PresetController::class, 'show'])->name('presets.show');
        Route::patch('presets/{preset}', [PresetController::class, 'update'])->name('presets.update');
        Route::delete('presets/{preset}', [PresetController::class, 'destroy'])->name('presets.destroy');
        Route::post('presets/{preset}/learn-style', [PresetController::class, 'learnStyle'])->middleware(['sitex.verified', 'throttle:writer-ai'])->name('presets.learn_style');
        Route::delete('presets/{preset}/style-draft', [PresetController::class, 'discardStyleDraft'])->name('presets.style_draft.discard');

        Route::get('batches', [BatchController::class, 'index'])->name('batches.index');
        Route::post('batches/preview', [BatchController::class, 'preview'])->name('batches.preview');
        Route::post('batches', [BatchController::class, 'store'])->middleware(['sitex.verified', 'throttle:writer-ai'])->name('batches.store');
        Route::get('batches/{batch}', [BatchController::class, 'show'])->name('batches.show');
        Route::post('batches/{batch}/pause', [BatchController::class, 'pause'])->name('batches.pause');
        Route::post('batches/{batch}/resume', [BatchController::class, 'resume'])->middleware('throttle:writer-ai')->name('batches.resume');
        Route::post('batches/{batch}/cancel', [BatchController::class, 'cancel'])->name('batches.cancel');
        Route::post('batches/{batch}/retry', [BatchController::class, 'retry'])->middleware('throttle:writer-ai')->name('batches.retry');

        Route::get('articles', [ArticleController::class, 'index'])->name('articles.index');
        Route::post('articles', [ArticleController::class, 'store'])->middleware(['sitex.verified', 'throttle:writer-ai'])->name('articles.store');

        Route::prefix('articles/{article}')->group(function (): void {
            Route::get('/', [ArticleController::class, 'show'])->name('articles.show');
            Route::patch('/', [ArticleController::class, 'update'])->name('articles.update');
            Route::post('cancel', [ArticleController::class, 'cancel'])->name('articles.cancel');
            Route::get('workflows', [ArticleController::class, 'workflows'])->name('articles.workflows.index');
            Route::post('workflows', [ArticleController::class, 'startWorkflow'])->middleware(['sitex.verified', 'throttle:writer-ai'])->name('articles.workflows.start');
            Route::post('workflows/resume', [ArticleController::class, 'resume'])->middleware('throttle:writer-ai')->name('articles.workflows.resume');
            Route::post('workflows/cancel', [ArticleController::class, 'cancelWorkflow'])->name('articles.workflows.cancel');

            Route::get('research', [ArticleResearchController::class, 'dossier'])->name('articles.research');
            Route::get('evidence', [ArticleResearchController::class, 'evidence'])->name('articles.evidence');
            Route::get('brief', [ArticleResearchController::class, 'brief'])->name('articles.brief');
            Route::post('brief/approve', [ArticleResearchController::class, 'approveBrief'])->middleware('throttle:writer-ai')->name('articles.brief.approve');
            Route::get('outline', [ArticleResearchController::class, 'outline'])->name('articles.outline');
            Route::post('outline/approve', [ArticleResearchController::class, 'approveOutline'])->middleware('throttle:writer-ai')->name('articles.outline.approve');

            Route::get('editor', [ArticleContentController::class, 'editor'])->name('articles.editor');
            Route::get('versions', [ArticleContentController::class, 'versions'])->name('articles.versions.index');
            Route::post('versions', [ArticleContentController::class, 'save'])->name('articles.versions.store');
            Route::put('autosave', [ArticleContentController::class, 'autosave'])->middleware('throttle:writer-autosave')->name('articles.autosave');
            Route::get('diff', [ArticleContentController::class, 'diff'])->name('articles.diff');
            Route::get('versions/{version:id}', [ArticleContentController::class, 'version'])->name('articles.versions.show');
            Route::post('versions/{version:id}/restore', [ArticleContentController::class, 'restore'])->name('articles.versions.restore');
            Route::get('revisions', [ArticleContentController::class, 'revisions'])->name('articles.revisions.index');
            Route::post('revisions', [ArticleContentController::class, 'rewrite'])->middleware('throttle:writer-ai')->name('articles.revisions.store');
            Route::get('revisions/{revision:id}', [ArticleContentController::class, 'revision'])->name('articles.revisions.show');
            Route::post('revisions/{revision:id}/apply', [ArticleContentController::class, 'applyRevision'])->name('articles.revisions.apply');
            Route::post('revisions/{revision:id}/discard', [ArticleContentController::class, 'discardRevision'])->name('articles.revisions.discard');

            Route::get('claims', [ArticleReviewController::class, 'claims'])->name('articles.claims.index');
            Route::get('claims/{claim:id}', [ArticleReviewController::class, 'claim'])->name('articles.claims.show');
            Route::post('claims/{claim:id}/verify', [ArticleReviewController::class, 'verifyClaim'])->name('articles.claims.verify');
            Route::get('fact-checks', [ArticleReviewController::class, 'factChecks'])->name('articles.fact-checks');
            Route::get('quality', [ArticleReviewController::class, 'quality'])->name('articles.quality');
            Route::post('quality/audit', [ArticleReviewController::class, 'audit'])->name('articles.quality.audit');
            Route::get('approval', [ArticleReviewController::class, 'checklist'])->name('articles.approval');
            Route::post('approve', [ArticleReviewController::class, 'approve'])->name('articles.approve');
            Route::post('request-changes', [ArticleReviewController::class, 'reject'])->name('articles.request-changes');
            Route::post('duplicate-decision', [ArticleReviewController::class, 'duplicateDecision'])->name('articles.duplicate-decision');
            Route::get('usage', [ArticleReviewController::class, 'usage'])->name('articles.usage');
        });
    });

// ---- Admin API (Super Admin; cost views also BILLING_ADMIN; monitor also SUPPORT) ----
$manage = 'sitex.platform:platform.ai.manage';
$costs = 'sitex.platform:platform.ai.view_costs';
$jobs = 'sitex.platform:platform.jobs.manage';

Route::prefix('api/admin/v1/writer')
    ->middleware(['api', 'auth:sanctum', 'sitex.active', 'sitex.platform', 'throttle:api'])
    ->name('admin.writer.')
    ->group(function () use ($manage, $costs, $jobs): void {
        Route::get('settings', [AdminWriterController::class, 'settings'])->middleware($manage)->name('settings.show');
        Route::patch('settings', [AdminWriterController::class, 'updateSettings'])->middleware($manage)->name('settings.update');
        Route::get('workspaces/{workspace}/settings', [AdminWriterController::class, 'workspaceSettings'])->middleware($manage)->name('workspace-settings.show');
        Route::patch('workspaces/{workspace}/settings', [AdminWriterController::class, 'updateWorkspaceSettings'])->middleware($manage)->name('workspace-settings.update');
        Route::get('routing', [AdminWriterController::class, 'routing'])->middleware($manage)->name('routing');
        Route::get('costs', [AdminWriterController::class, 'costs'])->middleware($costs)->name('costs');
        Route::get('runs', [AdminWriterController::class, 'runs'])->middleware($jobs)->name('runs');
        Route::get('failures', [AdminWriterController::class, 'failures'])->middleware($jobs)->name('failures');
        Route::get('abuse', [AdminWriterController::class, 'abuse'])->middleware($manage)->name('abuse');
    });
