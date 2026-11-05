<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Workflows\Engine\WorkflowRegistry;
use SiteXPost\Modules\Writer\Console\RunAutonomousWriter;
use SiteXPost\Modules\Writer\Console\SyncWriter;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\WritingBatch;
use SiteXPost\Modules\Writer\Domain\WritingPreset;
use SiteXPost\Modules\Writer\Policies\WriterPolicy;
use SiteXPost\Modules\Writer\Queries\ArticleCatalogQuery;
use SiteXPost\Modules\Writer\Support\WriterSettings;
use SiteXPost\Modules\Writer\Workflows\DraftWorkflow;
use SiteXPost\Modules\Writer\Workflows\OutlineWorkflow;
use SiteXPost\Modules\Writer\Workflows\RecheckWorkflow;
use SiteXPost\Modules\Writer\Workflows\ResearchWorkflow;
use SiteXPost\Modules\Writer\Workflows\RewriteWorkflow;

/**
 * Phase 5 — AI Content Writer, Evidence Research & Fact-Check Engine.
 */
final class WriterServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        Article::class => WriterPolicy::class,
        WritingPreset::class => WriterPolicy::class,
        WritingBatch::class => WriterPolicy::class,
    ];

    public function register(): void
    {
        $this->app->scoped(WriterSettings::class);
        $this->app->bind(ArticleCatalog::class, ArticleCatalogQuery::class);
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(WorkflowRegistry::class);
        foreach ([ResearchWorkflow::class, OutlineWorkflow::class, DraftWorkflow::class, RecheckWorkflow::class, RewriteWorkflow::class] as $wf) {
            $registry->register($wf);
        }

        $key = static function (Request $request, string $prefix): string {
            $workspace = $request->route('workspace');
            $wsKey = is_object($workspace) && isset($workspace->id) ? (string) $workspace->id : (string) $workspace;

            return $prefix.':'.($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.$wsKey;
        };
        // Anything that starts paid AI work.
        RateLimiter::for('writer-ai', static fn (Request $request): Limit => Limit::perMinute(20)->by($key($request, 'writer-ai')));
        // Editor autosave (client debounces; this is the hard cap).
        RateLimiter::for('writer-autosave', static fn (Request $request): Limit => Limit::perMinute(30)->by($key($request, 'writer-autosave')));

        if ($this->app->runningInConsole()) {
            $this->commands([SyncWriter::class, RunAutonomousWriter::class]);
        }
    }
}
