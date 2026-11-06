<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Publishing\Console;

use Illuminate\Console\Command;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Publishing\Actions\ManageJobs;
use SiteXPost\Modules\Publishing\Domain\PublishingBatch;
use SiteXPost\Modules\Publishing\Domain\PublishingJob;
use SiteXPost\Modules\Publishing\Domain\PublishingSchedule;
use SiteXPost\Modules\Publishing\Jobs\RunPublishingJob;
use SiteXPost\Modules\Publishing\Support\PublishingStatus as S;
use SiteXPost\Modules\Publishing\Support\SitePolicy;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

/**
 * Every minute: (1) start due schedules, (2) mark schedules missed beyond
 * the site's grace period — they are NOT published late automatically, a
 * human reschedules or retries, (3) re-dispatch publishing jobs whose
 * retry time passed and whose lock expired (lost queue message, worker
 * crash). The atomic claim in ExecutePublish makes double dispatch harmless.
 */
final class DispatchPublishing extends Command
{
    protected $signature = 'sitex:publishing:dispatch';

    protected $description = 'Start due scheduled posts, recover missed schedules and stuck publishing jobs';

    public function handle(TenantContext $context, ManageJobs $jobs): int
    {
        // Reviewed scope bypass: system scheduler, read-only selection of ids.
        $due = $context->bypass(fn () => PublishingSchedule::query()->where('status', 'pending')->where('scheduled_at', '<=', now())->limit(500)->get(['id', 'workspace_id']));
        $stuck = $context->bypass(fn () => PublishingJob::query()->where('status', S::PUBLISHING)->where('next_attempt_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))->limit(500)->get(['id', 'workspace_id']));

        $started = $missed = $requeued = 0;
        $byWs = $due->groupBy('workspace_id')->map(fn ($g) => ['due' => $g, 'stuck' => collect()])->all();
        foreach ($stuck->groupBy('workspace_id') as $ws => $g) {
            $byWs[$ws] = ['due' => $byWs[$ws]['due'] ?? collect(), 'stuck' => $g];
        }
        foreach ($byWs as $workspaceId => $sets) {
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace === null || $workspace->status !== WorkspaceStatus::Active) {
                continue;
            }
            $context->runAs($workspace, null, function () use ($sets, $jobs, &$started, &$missed, &$requeued): void {
                foreach ($sets['due'] as $row) {
                    $s = PublishingSchedule::query()->find($row->id);
                    $job = $s === null ? null : PublishingJob::query()->find($s->publishing_job_id);
                    if ($s === null || $job === null || $job->status !== S::SCHEDULED) {
                        $s?->forceFill(['status' => 'canceled'])->save();

                        continue;
                    }
                    if ($job->publishing_batch_id !== null && PublishingBatch::query()->whereKey($job->publishing_batch_id)->where('status', 'paused')->exists()) {
                        continue;
                    }
                    $grace = (int) SitePolicy::for($job->site_id)['missed_grace_minutes'];
                    if ($s->scheduled_at !== null && $s->scheduled_at->lt(now()->subMinutes($grace))) {
                        $s->forceFill(['status' => 'missed', 'note' => 'missed_schedule'])->save();
                        $job->forceFill(['status' => S::FAILED, 'last_error_code' => 'missed_schedule', 'last_error_message' => __('publishing.codes.missed_schedule')])->save();
                        Audit::record('publishing.schedule_missed', $job, ['scheduled_at' => $s->scheduled_at->toIso8601ZuluString()], actorType: 'system');
                        $missed++;

                        continue;
                    }
                    $s->forceFill(['status' => 'dispatched', 'dispatched_at' => now()])->save();
                    $jobs->startPublishing($job);
                    $started++;
                }
                foreach ($sets['stuck'] as $row) {
                    RunPublishingJob::dispatch((string) $row->id);
                    $requeued++;
                }
            });
        }
        $this->info("Started {$started}, missed {$missed}, re-dispatched {$requeued}.");

        return self::SUCCESS;
    }
}
