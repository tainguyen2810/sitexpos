<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SiteXPost\Kernel\Logging\SecretRedactor;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\System\Backup\BackupManager;
use SiteXPost\Modules\System\Domain\UpgradeRun;
use SiteXPost\Modules\System\Support\MigrationStatus;
use SiteXPost\Modules\System\Support\SystemSettings;
use Throwable;

/**
 * Upgrade run: optional pre-upgrade backup → re-check (no destructive
 * migration) → `migrate --force` (the only command executed) → insert-only
 * registry sync → health check → record the new version. Never runs
 * arbitrary commands, never touches APP_KEY, never rolls back automatically
 * (rollback = restore the pre-upgrade backup, ROLLBACK-PLAN.md).
 */
final class RunUpgrade implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $runId, public readonly bool $backupFirst)
    {
        $this->onQueue('critical');
    }

    public function handle(MigrationStatus $migrations, SystemSettings $settings, BackupManager $backups): void
    {
        $run = UpgradeRun::query()->find($this->runId);
        if ($run === null || $run->status !== 'queued') {
            return;
        }
        $steps = [];
        $step = static function (string $name, string $status, ?string $note = null) use (&$steps, $run): void {
            $steps[] = ['step' => $name, 'status' => $status, 'note' => $note, 'at' => now()->toIso8601ZuluString()];
            $run->forceFill(['steps' => $steps])->save();
        };
        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();

        try {
            if ($this->backupFirst) {
                $backup = $backups->run($backups->start('pre_upgrade', $run->requested_by));
                if ($backup->status !== 'succeeded') {
                    throw new RuntimeException('pre_upgrade_backup_failed: '.$backup->error);
                }
                $step('backup', 'done', $backup->file_name);
            }

            $pending = $migrations->pending();
            if (array_filter($pending, fn (array $m) => $m['destructive']) !== []) {
                throw new RuntimeException('destructive_migrations');
            }
            $names = array_column($pending, 'name');
            if ($names !== []) {
                $code = Artisan::call('migrate', ['--force' => true]);
                if ($code !== 0) {
                    throw new RuntimeException('migration_failed: '.SecretRedactor::redact(Artisan::output(), 400));
                }
            }
            $step('migrate', 'done', count($names).' migration(s)');

            $code = Artisan::call('db:seed', ['--force' => true, '--class' => 'Database\\Seeders\\DatabaseSeeder']);
            $step('system_defaults', $code === 0 ? 'done' : 'warning');

            DB::select('select 1');
            if ($migrations->pending() !== []) {
                throw new RuntimeException('migrations_still_pending');
            }
            $step('health_check', 'done');

            $settings->set('system.version', (string) config('sitex.version'), $run->requested_by);
            $run->forceFill(['status' => 'succeeded', 'migrations' => $names, 'finished_at' => now()])->save();
            Audit::record('system.upgraded', $run, ['from' => $run->from_version, 'to' => $run->to_version, 'migrations' => $names], actorId: $run->requested_by, actorType: 'staff');
        } catch (Throwable $e) {
            $step('failed', 'failed', SecretRedactor::redact($e->getMessage(), 300));
            $run->forceFill(['status' => 'failed', 'error' => SecretRedactor::redact($e->getMessage(), 450), 'finished_at' => now()])->save();
            Audit::record('system.upgrade_failed', $run, ['error' => SecretRedactor::redact($e->getMessage(), 200)], actorId: $run->requested_by, actorType: 'staff');
            SendSystemAlert::dispatch('upgrade', 'critical', 'Upgrade '.$run->to_version.' failed: '.SecretRedactor::redact($e->getMessage(), 200), true);
        }
    }
}
