<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Decides whether SiteX Post is already installed. The decision never relies on
 * a single file: ANY of these signals locks the installer —
 *  1. the lock file written at the end of the installation,
 *  2. SITEX_INSTALLED=true in .env (written in the same step),
 *  3. `system.installed_at` in the system_settings table,
 *  4. at least one SUPER_ADMIN user in the database (covers servers that were
 *     set up with the CLI before the installer existed).
 * Deleting the lock file therefore does not reopen the installer.
 */
final class InstallationState
{
    public const LOCK_FILE = 'installed.json';

    public const PROGRESS_FILE = 'progress.json';

    /** @var array{installed: bool, signals: list<string>}|null */
    private ?array $memo = null;

    public function __construct(private readonly InstallerStorage $storage) {}

    public function isInstalled(): bool
    {
        return $this->evaluate()['installed'];
    }

    /**
     * @return list<string>
     */
    public function signals(): array
    {
        return $this->evaluate()['signals'];
    }

    public function forget(): void
    {
        $this->memo = null;
    }

    /**
     * Installer progress (non-secret): completed steps, run log, failure.
     *
     * @return array<string, mixed>
     */
    public function progress(): array
    {
        return $this->storage->readJson(self::PROGRESS_FILE) ?? ['steps' => [], 'run' => null];
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    public function saveProgress(array $progress): void
    {
        $this->storage->writeJson(self::PROGRESS_FILE, $progress);
    }

    public function markStep(string $step): void
    {
        $p = $this->progress();
        $steps = is_array($p['steps'] ?? null) ? $p['steps'] : [];
        $steps[$step] = now()->toIso8601ZuluString();
        $p['steps'] = $steps;
        $this->saveProgress($p);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function writeLock(array $data): void
    {
        $this->storage->writeJson(self::LOCK_FILE, $data);
        $this->forget();
    }

    /**
     * @return array{installed: bool, signals: list<string>}
     */
    private function evaluate(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $signals = [];
        if ($this->storage->exists(self::LOCK_FILE)) {
            $signals[] = 'lock_file';
        }
        if ((bool) config('sitex.installer.installed')) {
            $signals[] = 'env_flag';
        }
        foreach ($this->databaseSignals() as $s) {
            $signals[] = $s;
        }

        return $this->memo = ['installed' => $signals !== [], 'signals' => $signals];
    }

    /**
     * @return list<string>
     */
    private function databaseSignals(): array
    {
        // No APP_KEY = nothing was configured yet; do not even try a connection.
        if ((string) config('app.key') === '') {
            return [];
        }

        $signals = [];
        try {
            $connection = DB::connection();
            $schema = Schema::connection($connection->getName());
            if ($schema->hasTable('system_settings')
                && $connection->table('system_settings')->where('key', 'system.installed_at')->whereNotNull('value')->exists()) {
                $signals[] = 'database_marker';
            }
            if ($schema->hasTable('users')
                && $connection->table('users')->where('platform_role', 'SUPER_ADMIN')->exists()) {
                $signals[] = 'super_admin_exists';
            }
        } catch (Throwable) {
            // Database not reachable/configured yet: rely on the file/env signals.
        }

        return $signals;
    }
}
