<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\System\Backup\BackupManager;
use SiteXPost\Modules\System\Domain\SystemBackup;

final class BackupVerify extends Command
{
    protected $signature = 'sitex:backup:verify {id? : backup id (default: latest successful)} {--restore-test= : scratch MySQL database named *_restore_test to load the dump into, then drop}';

    protected $description = 'Decrypt a backup and verify checksums (optionally a test restore into a scratch database)';

    public function handle(BackupManager $manager): int
    {
        $id = $this->argument('id');
        $backup = $id !== null ? SystemBackup::query()->find($id) : SystemBackup::query()->where('status', 'succeeded')->orderByDesc('finished_at')->first();
        if ($backup === null) {
            $this->error('Backup not found.');

            return self::FAILURE;
        }
        $restore = $this->option('restore-test');
        $r = $manager->verify($backup, is_string($restore) && $restore !== '' ? $restore : null);
        foreach ($r['checks'] as $k => $ok) {
            $this->line(($ok ? '[OK]   ' : '[FAIL] ').$k);
        }
        if ($r['tables_restored'] !== null) {
            $this->line('Tables restored into scratch database: '.$r['tables_restored']);
        }
        if (! $r['ok']) {
            $this->error('Verification failed'.($r['error'] ? ': '.$r['error'] : ''));

            return self::FAILURE;
        }
        $this->info('Backup verified.');

        return self::SUCCESS;
    }
}
