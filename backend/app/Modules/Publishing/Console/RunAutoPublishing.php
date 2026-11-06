<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Publishing\Actions\ManageJobs;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\PublishingPolicy;
use SiteXPost\Modules\Publishing\Domain\WordPressPostMapping;
use SiteXPost\Modules\Publishing\Support\PublishingLimits;
use SiteXPost\Modules\Publishing\Support\PublishingStatus as S;
use SiteXPost\Modules\Publishing\Support\ScheduleRules;
use SiteXPost\Modules\Publishing\Support\SitePolicy;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;
use SiteXPost\Modules\Writer\Contracts\ArticleCatalog;

/**
 * Mode D — automatic publishing, ONLY for sites whose owner explicitly
 * enabled it (and the platform allows it). Picks articles that a human
 * already APPROVED in the Writer with no open fact-check issue, schedules
 * them in the next allowed slot and runs every gate exactly like a manual
 * job. Never publishes an unapproved draft.
 */
final class RunAutoPublishing extends Command
{
    protected $signature = 'sitex:publishing:auto {--per-site=2}';

    protected $description = 'Schedule approved articles for sites with auto-publish explicitly enabled';

    public function handle(TenantContext $context, ManageJobs $jobs, ArticleCatalog $articles, SiteDirectory $sites, ScheduleRules $rules): int
    {
        $policies = $context->bypass(fn () => PublishingPolicy::query()->where('auto_publish_enabled', true)->get(['id', 'workspace_id', 'site_id']));
        $created = 0;
        foreach ($policies->groupBy('workspace_id') as $workspaceId => $group) {
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace === null || $workspace->status !== WorkspaceStatus::Active || ! PublishingLimits::for((string) $workspaceId)['auto_publish_allowed']) {
                continue;
            }
            $context->runAs($workspace, null, function () use ($group, $jobs, $articles, $sites, $rules, &$created): void {
                foreach ($group as $p) {
                    $profile = $sites->publishingProfile($p->site_id);
                    if ($profile === null || ! $profile['paired']) {
                        continue;
                    }
                    $policy = SitePolicy::for($p->site_id);
                    $n = 0;
                    foreach ($articles->list($p->site_id, ['APPROVED'], 50) as $a) {
                        if ($n >= (int) $this->option('per-site') || $a['unresolved_critical'] > 0 || $a['unresolved_high'] > 0 || $a['is_demo']) {
                            continue;
                        }
                        if (PublishingJob::query()->where('active_key', $p->site_id.':'.$a['id'])->exists()
                            || WordPressPostMapping::query()->where('site_id', $p->site_id)->where('article_id', $a['id'])->exists()
                            || PublishingJob::query()->where('site_id', $p->site_id)->where('article_id', $a['id'])->where('status', S::CANCELLED)->exists()) {
                            continue; // already handled, or a human cancelled it before
                        }
                        $slot = $rules->nextSlot(CarbonImmutable::now('UTC')->addMinutes(30), $profile['timezone'], $p->site_id, $a['campaign_id'], $policy, PublishingLimits::for($p->workspace_id));
                        if ($slot === null) {
                            break;
                        }
                        try {
                            $job = $jobs->create(['article_id' => $a['id'], 'mode' => 'auto', 'scheduled_at' => $slot->toIso8601ZuluString()], null, 'auto:'.$a['id'], 'system_auto');
                            if ($job->status === S::READY_FOR_REVIEW) {
                                $jobs->approve($job, null);
                                $n++;
                                $created++;
                            }
                        } catch (ApiException $e) {
                            $this->warn("Article {$a['id']}: {$e->errorCode}");
                        }
                    }
                }
            });
        }
        $this->info("Scheduled {$created} auto-publish jobs.");

        return self::SUCCESS;
    }
}
