<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Agent\Domain\AutomationSchedule;
use SiteXPost\Modules\Agent\Engine\AgentOrchestrator;
use SiteXPost\Modules\Agent\Engine\ScheduleCalculator;
use SiteXPost\Modules\Agent\Support\AgentLog;
use SiteXPost\Modules\Workspace\Domain\Workspace;
use SiteXPost\Modules\Workspace\Enums\WorkspaceStatus;

/**
 * Starts due agent runs. Each due slot is claimed with a compare-and-swap
 * on next_run_at and started with idempotency key schedule:slot, so two
 * scheduler instances (or a retried tick) never create duplicate runs.
 */
final class RunAgentSchedules extends Command
{
    protected $signature = 'sitex:agent:schedule {--limit=100}';

    protected $description = 'Start SEO agent runs whose schedule is due';

    public function handle(TenantContext $context, AgentOrchestrator $orchestrator): int
    {
        $due = $context->bypass(fn () => AutomationSchedule::query()->where('status', 'active')->whereNotNull('next_run_at')->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')->limit((int) $this->option('limit'))->get());
        $started = 0;
        foreach ($due as $s) {
            $ws = Workspace::query()->find($s->workspace_id);
            if ($ws === null || $ws->status !== WorkspaceStatus::Active) {
                continue;
            }
            $slot = $s->next_run_at;
            $next = ScheduleCalculator::next($s->frequency, $s->local_time, $s->timezone, $s->day_of_week, $s->day_of_month, CarbonImmutable::now('UTC'));
            $claimed = $context->bypass(fn () => AutomationSchedule::query()->whereKey($s->id)->where('next_run_at', $slot)->update(['next_run_at' => $next, 'last_run_at' => now()]));
            if ($claimed !== 1) {
                continue;
            }
            $context->runAs($ws, null, function () use ($s, $slot, $orchestrator, &$started): void {
                try {
                    $run = $orchestrator->start($s->site_id, $s->kind, 'schedule', null, $s->id, 'schedule:'.$s->id.':'.$slot?->format('YmdHi'));
                    AutomationSchedule::query()->whereKey($s->id)->update(['last_run_id' => $run->id, 'last_status' => 'started', 'consecutive_failures' => 0]);
                    $started++;
                } catch (ApiException $e) {
                    AutomationSchedule::query()->whereKey($s->id)->update(['last_status' => 'skipped', 'consecutive_failures' => $s->consecutive_failures + 1]);
                    AgentLog::record('schedule.skipped', $e->errorCode, $s->site_id, null, null, ['schedule_id' => $s->id], 'system', null, 'warning');
                }
            });
        }
        $this->info("Started {$started} agent runs");

        return self::SUCCESS;
    }
}
