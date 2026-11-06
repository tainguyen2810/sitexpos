<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\System\Backup\BackupManager;

final class BackupPrune extends Command
{
    protected $signature = 'sitex:backup:prune';

    protected $description = 'Delete backup files older than the retention (keeps the newest successful one)';

    public function handle(BackupManager $manager): int
    {
        $this->info('Pruned '.$manager->prune().' backup file(s).');

        return self::SUCCESS;
    }
}
