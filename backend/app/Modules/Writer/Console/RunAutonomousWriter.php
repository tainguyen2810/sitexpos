<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Writer\Console;

use Illuminate\Console\Command;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Research\Contracts\PlannerDirectory;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Writer\Actions\Batches;
use SiteXPost\Modules\Writer\Domain\Article;
use SiteXPost\Modules\Writer\Domain\WritingBatch;
use SiteXPost\Modules\Writer\Support\WriterSettings;

/**
 * Autonomous mode (off by default; enabled per workspace by the platform):
 * once a day, for campaigns with auto_create_content, write up to N
 * human-APPROVED ideas. Articles stop at NEEDS_HUMAN_REVIEW — nothing is
 * approved or published automatically.
 */
final class RunAutonomousWriter extends Command
{
    protected $signature = 'sitex:writer:autonomous';

    protected $description = 'Queue AI Writer batches for campaigns with autonomous content creation enabled';

    public function handle(TenantContext $context, WriterSettings $settings): int
    {
        $workspaces = $context->bypass(fn () => Workspace::query()->get(['id']));
        $created = 0;
        foreach ($workspaces as $row) {
            if (! (bool) $settings->get('autonomous_enabled', $row->id)) {
                continue;
            }
            $workspace = Workspace::query()->find($row->id);
            if ($workspace === null) {
                continue;
            }
            $context->runAs($workspace, null, function () use ($settings, $workspace, &$created): void {
                $planner = app(PlannerDirectory::class);
                $perDay = $settings->int('autonomous_articles_per_day', $workspace->id);
                $today = WritingBatch::query()->where('source', 'autonomous')->where('created_at', '>=', now()->startOfDay())->sum('total');
                $left = $perDay - (int) $today;
                foreach ($planner->autonomousCampaigns() as $campaign) {
                    if ($left <= 0) {
                        break;
                    }
                    $ideas = $planner->approvedIdeas($campaign['id'], null, 200);
                    $taken = Article::query()->whereIn('content_idea_id', array_column($ideas, 'id'))->pluck('content_idea_id')->flip();
                    $pick = array_slice(array_values(array_filter($ideas, fn ($i) => ! $taken->has($i['id']))), 0, $left);
                    if ($pick === []) {
                        continue;
                    }
                    try {
                        $b = app(Batches::class)->create(['campaign_id' => $campaign['id'], 'content_idea_ids' => array_column($pick, 'id'), 'concurrency' => 1, 'settings' => ['review_mode' => 'final_only']],
                            null, 'auto:'.$campaign['id'].':'.now()->format('Ymd'), 'autonomous');
                        $left -= $b->total;
                        $created += $b->total;
                    } catch (\Throwable $e) {
                        report($e); // quota / budget / validation: skip this campaign today
                    }
                }
            });
        }
        $this->info("Queued {$created} autonomous articles.");

        return self::SUCCESS;
    }
}
