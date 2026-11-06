<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Console;

use Illuminate\Console\Command;

/**
 * Prints a new backup encryption key. It never writes .env and never touches
 * APP_KEY: the operator stores the key in .env AND somewhere off the server.
 */
final class BackupKey extends Command
{
    protected $signature = 'sitex:backup:key';

    protected $description = 'Generate a new SITEX_BACKUP_ENCRYPTION_KEY (printed only; .env is not modified)';

    public function handle(): int
    {
        if ((string) config('sitex.system.backup.encryption_key') !== '') {
            $this->warn('A backup key is already configured. Replacing it makes existing backups unreadable without the old key.');
        }
        $this->line('SITEX_BACKUP_ENCRYPTION_KEY=base64:'.base64_encode(random_bytes(32)));
        $this->info('Add this line to .env, then keep a copy outside this server (password manager / offline).');

        return self::SUCCESS;
    }
}
