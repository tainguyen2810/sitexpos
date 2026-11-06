<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Support;

use SiteXPost\Modules\Agent\Domain\AgentActionLog;

/**
 * Append-only agent action log (every decision, execution, failure and
 * human intervention). Rows are never updated.
 *
 * @param  array<string, mixed>  $context
 */
final class AgentLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(string $event, string $message, ?string $siteId = null, ?string $runId = null, ?string $taskId = null, array $context = [],
        string $actorType = 'agent', ?string $actorId = null, string $level = 'info'): void
    {
        $row = new AgentActionLog;
        $row->forceFill(['site_id' => $siteId, 'agent_run_id' => $runId, 'agent_task_id' => $taskId, 'event' => mb_substr($event, 0, 48),
            'level' => $level, 'message' => mb_substr($message, 0, 500), 'context' => $context, 'actor_type' => $actorType, 'actor_id' => $actorId])->save();
    }
}
