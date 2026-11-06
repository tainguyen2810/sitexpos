<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Console;

use Illuminate\Console\Command;
use SiteXPost\Modules\System\Backup\BackupCrypto;

/**
 * Manual restore helper: decrypts a .sxpbk file into a .tar the operator
 * extracts and restores by hand (BACKUP-RESTORE.md). Never restores anything itself.
 */
final class BackupDecrypt extends Command
{
    protected $signature = 'sitex:backup:decrypt {file : path to the .sxpbk file} {out : path of the .tar to write}';

    protected $description = 'Decrypt a backup archive for a manual restore';

    public function handle(): int
    {
        $in = (string) $this->argument('file');
        $out = (string) $this->argument('out');
        if (! is_file($in)) {
            $this->error('File not found.');

            return self::FAILURE;
        }
        if (file_exists($out)) {
            $this->error('Output exists; choose another path.');

            return self::FAILURE;
        }
        BackupCrypto::decryptFile($in, $out);
        @chmod($out, 0600);
        $this->info("Decrypted to {$out}. Contents: database.sql, storage.tar, env, manifest.json");

        return self::SUCCESS;
    }
}
