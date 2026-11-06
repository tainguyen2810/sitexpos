<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Queries;

use Carbon\CarbonInterface;
use SiteXPost\Modules\Agent\Contracts\AgentActivity;
use SiteXPost\Modules\Agent\Domain\AgentRun;
use SiteXPost\Modules\Agent\Domain\AgentTask;

final class AgentActivityQuery implements AgentActivity
{
    public function summary(?string $siteId, CarbonInterface $from, CarbonInterface $to): array
    {
        $runs = AgentRun::query()->whereBetween('created_at', [$from, $to])->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))->count();
        $tasks = AgentTask::query()->whereBetween('created_at', [$from, $to])->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->get(['tool', 'status']);

        $byTool = [];
        foreach ($tasks as $t) {
            $byTool[$t->tool] = ($byTool[$t->tool] ?? 0) + 1;
        }

        return [
            'runs' => $runs,
            'tasks_succeeded' => $tasks->where('status', 'succeeded')->count(),
            'tasks_failed' => $tasks->where('status', 'failed')->count(),
            'tasks_awaiting_approval' => $tasks->where('status', 'awaiting_approval')->count(),
            'by_tool' => $byTool,
        ];
    }

    public function pendingApprovals(?string $siteId): array
    {
        return AgentTask::query()->where('status', 'awaiting_approval')->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderBy('created_at')->limit(50)->get()
            ->map(fn (AgentTask $t): array => ['id' => $t->id, 'site_id' => $t->site_id, 'tool' => $t->tool, 'created_at' => $t->created_at?->toIso8601String()])
            ->values()->all();
    }
}
