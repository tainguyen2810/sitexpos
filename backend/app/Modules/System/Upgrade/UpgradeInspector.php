<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Upgrade;

use SiteXPost\Modules\Installer\Support\EnvironmentChecker;
use SiteXPost\Modules\System\Domain\SystemBackup;
use SiteXPost\Modules\System\Domain\UpgradeRun;
use SiteXPost\Modules\System\Support\MigrationStatus;
use SiteXPost\Modules\System\Support\SystemSettings;

/**
 * Upgrade Wizard state (Phase 9 §G). The code itself is deployed by the
 * operator (aaPanel/SSH, UPGRADE-GUIDE.md) — the web cannot verify or install
 * code safely. After the new code is on disk the wizard compares versions,
 * checks requirements and backups, and runs only additive pending migrations.
 */
final class UpgradeInspector
{
    public function __construct(
        private readonly SystemSettings $settings,
        private readonly MigrationStatus $migrations,
        private readonly EnvironmentChecker $environment,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $code = (string) config('sitex.version');
        // Read the recorded version from the database, never from a cached snapshot:
        // another process (installer, web container, another worker) may have written it
        // through a different cache store, and a stale snapshot would keep the workers
        // waiting for an upgrade that already happened.
        $this->settings->flush();
        $db = $this->settings->string('system.version');
        $pending = $this->migrations->pending();
        $destructive = array_values(array_filter($pending, fn (array $m) => $m['destructive']));
        $env = $this->environment->run();
        $failedReqs = array_values(array_filter($env['checks'], fn (array $c) => $c['status'] === 'FAIL'));
        $backup = SystemBackup::query()->where('status', 'succeeded')->orderByDesc('finished_at')->first();
        $recentBackup = $backup !== null && $backup->finished_at !== null && $backup->finished_at->greaterThan(now()->subDay());
        $running = UpgradeRun::query()->whereIn('status', ['queued', 'running'])->exists();

        $blockers = [];
        if ($destructive !== []) {
            $blockers[] = 'destructive_migrations';
        }
        if ($failedReqs !== []) {
            $blockers[] = 'requirements';
        }
        if ($running) {
            $blockers[] = 'in_progress';
        }
        $needed = $pending !== [] || ($db !== null && version_compare($db, $code, '<')) || $db === null;

        return [
            'current_version' => $db,
            'target_version' => $code,
            'upgrade_needed' => $needed,
            'changelog' => $this->changelog($db, $code),
            'requirements' => ['summary' => $env['summary'], 'failed' => $failedReqs],
            'migrations' => ['pending' => $pending, 'destructive' => $destructive, 'ran_count' => count($this->migrations->ran())],
            'backup' => [
                'last_success_at' => $backup?->finished_at?->toIso8601ZuluString(),
                'verified' => $backup?->verify_status === 'verified',
                'recent' => $recentBackup,
                'encryption_configured' => (string) config('sitex.system.backup.encryption_key') !== '',
            ],
            'maintenance' => [
                'down' => app()->isDownForMaintenance(),
                'required' => false, // additive migrations only through the web; see UPGRADE-GUIDE.md for maintenance mode
            ],
            'blockers' => $blockers,
            'can_upgrade' => $needed && $blockers === [],
            'runs' => UpgradeRun::query()->orderByDesc('created_at')->limit(10)->get()->map(fn (UpgradeRun $r) => [
                'id' => $r->id, 'from_version' => $r->from_version, 'to_version' => $r->to_version, 'status' => $r->status,
                'migrations' => $r->migrations ?? [], 'steps' => $r->steps ?? [], 'error' => $r->error,
                'created_at' => $r->created_at?->toIso8601ZuluString(), 'finished_at' => $r->finished_at?->toIso8601ZuluString(),
            ])->all(),
        ];
    }

    /**
     * Sections of CHANGELOG.md newer than $from up to $to.
     *
     * @return list<array{version: string, date: ?string, notes: list<string>}>
     */
    public function changelog(?string $from, string $to): array
    {
        $file = base_path('CHANGELOG.md');
        if (! is_file($file)) {
            return [];
        }
        $out = [];
        $current = null;
        foreach (preg_split('/\r?\n/', (string) file_get_contents($file)) ?: [] as $line) {
            if (preg_match('/^##\s*\[?(\d+\.\d+\.\d+)\]?\s*(?:-\s*(\S+))?/', $line, $m)) {
                if ($current !== null) {
                    $out[] = $current;
                }
                $current = ['version' => $m[1], 'date' => $m[2] ?? null, 'notes' => []];

                continue;
            }
            if ($current !== null && preg_match('/^\s*[-*]\s+(.+)$/', $line, $m)) {
                $current['notes'][] = trim($m[1]);
            }
        }
        if ($current !== null) {
            $out[] = $current;
        }

        return array_values(array_filter($out, fn (array $e) => version_compare($e['version'], $to, '<=') && ($from === null || version_compare($e['version'], $from, '>'))));
    }
}
