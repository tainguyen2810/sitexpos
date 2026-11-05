<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Enums;

/**
 * Workflow run states. Allowed transitions are the single source of truth
 * for WorkflowStateMachine (ARCHITECTURE.md §10.1).
 */
enum WorkflowStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case RetryScheduled = 'retry_scheduled';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Running, self::Canceled, self::Failed],
            self::Running => [self::Running, self::Pending, self::RetryScheduled, self::Succeeded, self::Failed, self::Canceled],
            self::RetryScheduled => [self::Running, self::Pending, self::Canceled, self::Failed],
            self::Failed => [self::Pending],
            self::Succeeded, self::Canceled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Canceled], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Running, self::RetryScheduled], true);
    }
}
