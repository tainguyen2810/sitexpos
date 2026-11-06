<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Console;

use Illuminate\Console\Command;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Actions\ArticleWorkflows;
use SiteXPost\Modules\Writer\Actions\Batches;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\WritingBatch;

/**
 * Reconcile articles whose workflow the engine failed / cancelled outside
 * a request, and advance running batches (start queued items when a slot
 * frees up).
 */
final class SyncWriter extends Command
{
    protected $signature = 'sitex:writer:sync';

    protected $description = 'Reconcile AI Writer articles with their workflows and advance bulk batches';

    public function handle(TenantContext $context, ArticleWorkflows $workflows, Batches $batches): int
    {
        // Reviewed scope bypass: system reconciler, read-only selection of ids.
        [$articles, $running] = $context->bypass(fn () => [
            Article::query()->whereIn('status', ['RESEARCHING', 'OUTLINING', 'WRITING', 'FACT_CHECKING', 'REVISING', 'QUALITY_REVIEW'])
                ->where('updated_at', '<', now()->subMinutes(2))->limit(500)->get(['id', 'workspace_id']),
            WritingBatch::query()->where('status', 'running')->limit(200)->get(['id', 'workspace_id']),
        ]);
        $n = 0;
        $ids = $articles->pluck('workspace_id')->merge($running->pluck('workspace_id'))->unique();
        foreach ($ids as $workspaceId) {
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace === null) {
                continue;
            }
            $context->runAs($workspace, null, function () use ($articles, $running, $workspaceId, $workflows, $batches, &$n): void {
                foreach ($articles->where('workspace_id', $workspaceId) as $row) {
                    $a = Article::query()->find($row->id);
                    if ($a !== null) {
                        $workflows->reconcile($a);
                        $n++;
                    }
                }
                foreach ($running->where('workspace_id', $workspaceId) as $row) {
                    $b = WritingBatch::query()->find($row->id);
                    if ($b !== null) {
                        $batches->advance($b);
                    }
                }
            });
        }
        $this->info("Checked {$n} articles and {$running->count()} batches.");

        return self::SUCCESS;
    }
}
