<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PharData;
use RuntimeException;
use SiteXPost\Kernel\Logging\SecretRedactor;
use SiteXPost\Modules\System\Domain\SystemBackup;
use Throwable;

/**
 * Backups (BACKUP-RESTORE.md): database dump + storage/app (media, generated
 * files) + .env (holds APP_KEY, needed to read encrypted columns), bundled,
 * encrypted with SITEX_BACKUP_ENCRYPTION_KEY and optionally copied to an
 * offsite filesystem disk. Commands run through Process with fixed argument
 * lists (no shell); the DB password is passed in the environment, never in
 * argv or logs. Restores are never automatic.
 */
final class BackupManager
{
    public function dir(): string
    {
        $dir = rtrim((string) config('sitex.system.backup.path'), '/');
        File::ensureDirectoryExists($dir, 0700);

        return $dir;
    }

    public function start(string $trigger, ?string $userId): SystemBackup
    {
        $backup = new SystemBackup;
        $backup->forceFill(['status' => 'running', 'trigger' => $trigger, 'requested_by' => $userId, 'started_at' => now()])->save();

        return $backup;
    }

    public function run(SystemBackup $backup): SystemBackup
    {
        $work = $this->dir().'/tmp-'.$backup->id;
        try {
            BackupCrypto::key();
            File::ensureDirectoryExists($work, 0700);
            $contents = [];

            $dbFile = $work.'/database.sql';
            $this->dumpDatabase($dbFile);
            $contents[] = 'database';

            $storageFile = $work.'/storage.tar';
            $this->archiveStorage($storageFile);
            $contents[] = 'storage';

            if (is_file(base_path('.env'))) {
                copy(base_path('.env'), $work.'/env');
                $contents[] = 'config';
            }

            $manifest = [
                'format' => 1,
                'app' => 'sitexpost',
                'version' => config('sitex.version'),
                'created_at' => now()->toIso8601ZuluString(),
                'database_driver' => DB::getDriverName(),
                'files' => [],
            ];
            foreach (['database.sql', 'storage.tar', 'env'] as $f) {
                if (is_file($work.'/'.$f)) {
                    $manifest['files'][$f] = ['sha256' => hash_file('sha256', $work.'/'.$f), 'bytes' => filesize($work.'/'.$f)];
                }
            }
            file_put_contents($work.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

            $bundle = $work.'/bundle.tar';
            $tar = new PharData($bundle);
            foreach (array_merge(array_keys($manifest['files']), ['manifest.json']) as $f) {
                $tar->addFile($work.'/'.$f, $f);
            }
            unset($tar);

            $name = 'sitexpost-backup-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.sxpbk';
            $final = $this->dir().'/'.$name;
            BackupCrypto::encryptFile($bundle, $final);
            @chmod($final, 0600);

            $backup->forceFill([
                'file_name' => $name,
                'size_bytes' => filesize($final),
                'sha256' => hash_file('sha256', $final),
                'contents' => $contents,
            ])->save();

            $this->copyOffsite($backup, $final);
            $backup->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();
        } catch (Throwable $e) {
            $backup->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => $this->safe($e)])->save();
        } finally {
            File::deleteDirectory($work);
        }

        return $backup->refresh();
    }

    /**
     * Decrypts, checks every checksum in the manifest and the dump header.
     * With $restoreDatabase, also loads the dump into a SCRATCH database whose
     * name must end with "_restore_test" and differ from the live database.
     *
     * @return array{ok: bool, checks: array<string, bool>, error: ?string, tables_restored: ?int}
     */
    public function verify(SystemBackup $backup, ?string $restoreDatabase = null): array
    {
        $checks = [];
        $work = $this->dir().'/verify-'.$backup->id;
        $tables = null;
        try {
            $file = $this->dir().'/'.$backup->file_name;
            $checks['file_present'] = $backup->file_name !== null && is_file($file);
            if (! $checks['file_present']) {
                throw new RuntimeException('backup_file_missing');
            }
            $checks['checksum'] = hash_file('sha256', $file) === $backup->sha256;
            File::ensureDirectoryExists($work, 0700);
            BackupCrypto::decryptFile($file, $work.'/bundle.tar');
            $checks['decrypt'] = true;

            (new PharData($work.'/bundle.tar'))->extractTo($work.'/x', null, true);
            $manifest = json_decode((string) @file_get_contents($work.'/x/manifest.json'), true);
            $checks['manifest'] = is_array($manifest) && ($manifest['app'] ?? null) === 'sitexpost';
            foreach ((array) ($manifest['files'] ?? []) as $name => $meta) {
                $checks['sha256:'.$name] = is_file($work.'/x/'.$name) && hash_file('sha256', $work.'/x/'.$name) === ($meta['sha256'] ?? '');
            }
            $head = (string) @file_get_contents($work.'/x/database.sql', false, null, 0, 200);
            $checks['database_dump'] = str_contains($head, 'MySQL dump') || str_starts_with($head, 'SQLite format 3') || str_contains($head, '-- SiteX SQLite copy');

            if ($restoreDatabase !== null) {
                $tables = $this->restoreToScratch($work.'/x/database.sql', $restoreDatabase);
                $checks['restore_test'] = $tables > 0;
            }

            $ok = ! in_array(false, $checks, true);
            $backup->forceFill(['verify_status' => $ok ? 'verified' : 'failed', 'verified_at' => now()])->save();

            return ['ok' => $ok, 'checks' => $checks, 'error' => null, 'tables_restored' => $tables];
        } catch (Throwable $e) {
            $backup->forceFill(['verify_status' => 'failed', 'verified_at' => now()])->save();

            return ['ok' => false, 'checks' => $checks, 'error' => $this->safe($e), 'tables_restored' => $tables];
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Deletes local (and offsite) files older than the retention; keeps the
     * newest successful backup whatever its age.
     */
    public function prune(): int
    {
        $days = max(1, (int) config('sitex.system.backup.retention_days', 14));
        $keep = SystemBackup::query()->where('status', 'succeeded')->orderByDesc('finished_at')->value('id');
        $count = 0;
        SystemBackup::query()->where('created_at', '<', now()->subDays($days))->whereNotNull('file_name')
            ->when($keep !== null, fn ($q) => $q->where('id', '!=', $keep))
            ->get()->each(function (SystemBackup $b) use (&$count): void {
                @unlink($this->dir().'/'.$b->file_name);
                if ($b->offsite_disk !== null) {
                    try {
                        Storage::disk($b->offsite_disk)->delete('sitexpost-backups/'.$b->file_name);
                    } catch (Throwable) {
                        // offsite deletion failure is reported by the next monitor run only
                    }
                }
                $b->forceFill(['file_name' => null])->save();
                $count++;
            });

        return $count;
    }

    private function dumpDatabase(string $out): void
    {
        $driver = DB::getDriverName();
        $c = DB::connection()->getConfig();

        if ($driver === 'sqlite') {
            $db = (string) ($c['database'] ?? '');
            if ($db === ':memory:' || ! is_file($db)) {
                file_put_contents($out, "-- SiteX SQLite copy (in-memory database: schema only, development)\n");

                return;
            }
            copy($db, $out);

            return;
        }
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            throw new RuntimeException('backup_driver_unsupported');
        }

        $result = Process::timeout((int) config('sitex.system.backup.timeout_seconds', 3600))
            ->env(['MYSQL_PWD' => (string) ($c['password'] ?? '')])
            ->run([
                (string) config('sitex.system.backup.mysqldump', 'mysqldump'),
                '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--set-gtid-purged=OFF',
                '--default-character-set=utf8mb4',
                '--host='.(string) ($c['host'] ?? '127.0.0.1'),
                '--port='.(string) ($c['port'] ?? 3306),
                '--user='.(string) ($c['username'] ?? ''),
                '--result-file='.$out,
                (string) ($c['database'] ?? ''),
            ]);
        if (! $result->successful()) {
            // --set-gtid-purged is unknown to some client builds: retry once without it.
            $result = Process::timeout((int) config('sitex.system.backup.timeout_seconds', 3600))
                ->env(['MYSQL_PWD' => (string) ($c['password'] ?? '')])
                ->run([
                    (string) config('sitex.system.backup.mysqldump', 'mysqldump'),
                    '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces',
                    '--default-character-set=utf8mb4',
                    '--host='.(string) ($c['host'] ?? '127.0.0.1'),
                    '--port='.(string) ($c['port'] ?? 3306),
                    '--user='.(string) ($c['username'] ?? ''),
                    '--result-file='.$out,
                    (string) ($c['database'] ?? ''),
                ]);
        }
        if (! $result->successful()) {
            throw new RuntimeException('mysqldump_failed: '.SecretRedactor::redact($result->errorOutput(), 300));
        }
    }

    private function archiveStorage(string $out): void
    {
        $root = storage_path('app');
        $exclude = array_filter([realpath($this->dir()) ?: null, realpath((string) config('sitex.installer.state_path')) ?: null]);
        $tar = new PharData($out);
        $files = File::allFiles($root, true);
        foreach ($files as $file) {
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }
            foreach ($exclude as $ex) {
                if (str_starts_with($path, $ex.'/')) {
                    continue 2;
                }
            }
            $tar->addFile($path, 'app/'.ltrim(substr($path, strlen((string) realpath($root))), '/'));
        }
        if (count($files) === 0 || $tar->count() === 0) {
            $tar->addFromString('app/.empty', '');
        }
        unset($tar);
    }

    private function copyOffsite(SystemBackup $backup, string $file): void
    {
        $disk = (string) config('sitex.system.backup.disk');
        if ($disk === '') {
            return;
        }
        try {
            $stream = fopen($file, 'rb');
            Storage::disk($disk)->writeStream('sitexpost-backups/'.basename($file), $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $backup->forceFill(['offsite_status' => 'uploaded', 'offsite_disk' => $disk])->save();
        } catch (Throwable $e) {
            $backup->forceFill(['offsite_status' => 'failed', 'offsite_disk' => $disk, 'error' => 'offsite: '.$this->safe($e)])->save();
        }
    }

    private function restoreToScratch(string $sql, string $database): int
    {
        $live = (string) DB::connection()->getConfig('database');
        if (! preg_match('/^[A-Za-z0-9_]+_restore_test$/', $database) || $database === $live) {
            throw new RuntimeException('restore_target_not_allowed');
        }
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('restore_test_requires_mysql');
        }
        $c = DB::connection()->getConfig();
        DB::statement('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $result = Process::timeout((int) config('sitex.system.backup.timeout_seconds', 3600))
            ->env(['MYSQL_PWD' => (string) ($c['password'] ?? '')])
            ->input((string) file_get_contents($sql))
            ->run([
                (string) config('sitex.system.backup.mysql', 'mysql'),
                '--host='.(string) ($c['host'] ?? '127.0.0.1'),
                '--port='.(string) ($c['port'] ?? 3306),
                '--user='.(string) ($c['username'] ?? ''),
                $database,
            ]);
        if (! $result->successful()) {
            throw new RuntimeException('restore_failed: '.SecretRedactor::redact($result->errorOutput(), 300));
        }
        $count = (int) (DB::selectOne('SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$database])->c ?? 0);
        DB::statement('DROP DATABASE `'.$database.'`');

        return $count;
    }

    private function safe(Throwable $e): string
    {
        $msg = $e->getMessage();
        $pw = (string) DB::connection()->getConfig('password');
        if ($pw !== '') {
            $msg = str_replace($pw, '[REDACTED]', $msg);
        }

        return SecretRedactor::redact($msg, 450);
    }
}
