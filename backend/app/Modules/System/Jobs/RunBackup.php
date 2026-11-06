<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SiteXPost\Modules\System\Backup\BackupManager;
use SiteXPost\Modules\System\Domain\SystemBackup;

final class RunBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1; // a half-written backup is never retried blindly; the next run starts clean

    public int $timeout = 3600;

    public function __construct(public readonly string $backupId)
    {
        $this->onQueue('default');
    }

    public function handle(BackupManager $manager): void
    {
        $backup = SystemBackup::query()->find($this->backupId);
        if ($backup === null || $backup->status !== 'running') {
            return;
        }
        $backup = $manager->run($backup);
        if ($backup->status === 'failed') {
            SendSystemAlert::dispatch('backup', 'critical', __('system.health.backup_failed').' '.$backup->error, true);
        }
    }
}
