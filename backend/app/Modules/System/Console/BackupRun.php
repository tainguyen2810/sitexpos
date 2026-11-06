<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use SiteXPost\Modules\System\Backup\BackupManager;
use SiteXPost\Modules\System\Domain\SystemBackup;
use SiteXPost\Modules\System\Jobs\SendSystemAlert;
use SiteXPost\Modules\System\Support\SystemSettings;

final class BackupRun extends Command
{
    protected $signature = 'sitex:backup:run {--scheduled : only run when the daily schedule is due} {--verify : verify the archive afterwards}';

    protected $description = 'Create an encrypted backup (database + storage + .env)';

    public function handle(BackupManager $manager, SystemSettings $settings): int
    {
        if ($this->option('scheduled')) {
            if (! (bool) $settings->get('backup.schedule_enabled')) {
                return self::SUCCESS;
            }
            $tz = (string) ($settings->get('general.default_timezone') ?: 'UTC');
            $at = Carbon::parse(now($tz)->format('Y-m-d').' '.((string) ($settings->get('backup.schedule_time') ?: '02:00')), $tz)->utc();
            $doneToday = SystemBackup::query()->whereIn('status', ['succeeded', 'running'])->where('created_at', '>=', $at)->exists();
            if (now()->lt($at) || $doneToday) {
                return self::SUCCESS;
            }
        }
        if (SystemBackup::query()->where('status', 'running')->where('started_at', '>', now()->subHours(2))->exists()) {
            $this->warn('A backup is already running.');

            return self::FAILURE;
        }

        $backup = $manager->run($manager->start($this->option('scheduled') ? 'schedule' : 'manual', null));
        if ($backup->status !== 'succeeded') {
            $this->error('Backup failed: '.$backup->error);
            SendSystemAlert::dispatch('backup', 'critical', __('system.health.backup_failed').' '.$backup->error, true);

            return self::FAILURE;
        }
        $this->info("Backup {$backup->file_name} ({$backup->size_bytes} bytes, sha256 {$backup->sha256}) offsite: {$backup->offsite_status}");
        if ($this->option('verify')) {
            $r = $manager->verify($backup);
            $this->line('Verify: '.($r['ok'] ? 'OK' : 'FAILED '.$r['error']));
            if (! $r['ok']) {
                foreach ($r['checks'] as $check => $passed) {
                    if (! $passed) {
                        $this->error('Failed check: '.$check);
                    }
                }

                return self::FAILURE; // deploy scripts rely on the exit code
            }
        }

        return self::SUCCESS;
    }
}
