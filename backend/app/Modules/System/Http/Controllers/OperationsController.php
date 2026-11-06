<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\System\Backup\BackupCrypto;
use SiteXPost\Modules\System\Backup\BackupManager;
use SiteXPost\Modules\System\Domain\SystemBackup;
use SiteXPost\Modules\System\Domain\UpgradeRun;
use SiteXPost\Modules\System\Http\Requests\ReauthRequest;
use SiteXPost\Modules\System\Jobs\RunBackup;
use SiteXPost\Modules\System\Jobs\RunUpgrade;
use SiteXPost\Modules\System\Jobs\VerifyBackup;
use SiteXPost\Modules\System\Support\SystemSettings;
use SiteXPost\Modules\System\Upgrade\UpgradeInspector;

/**
 * Upgrade Wizard and backups (Phase 9 §G, §I). Sensitive actions require the
 * staff password again (current_password) and run as queued jobs.
 */
final class OperationsController
{
    public function upgrade(UpgradeInspector $inspector): JsonResponse
    {
        return new JsonResponse(['data' => $inspector->status()]);
    }

    public function startUpgrade(ReauthRequest $request, UpgradeInspector $inspector): JsonResponse
    {
        $status = $inspector->status();
        $backupFirst = (bool) $request->validated('backup_first', false);

        if (! $status['upgrade_needed']) {
            throw new ApiException(422, 'nothing_to_upgrade', __('system.upgrade.nothing'));
        }
        if (in_array('destructive_migrations', $status['blockers'], true)) {
            throw new ApiException(409, 'destructive_migrations', __('system.upgrade.destructive'), ['migrations' => array_column($status['migrations']['destructive'], 'name')]);
        }
        if (in_array('requirements', $status['blockers'], true)) {
            throw new ApiException(409, 'requirements_not_met', __('system.upgrade.requirements'));
        }
        if (! $status['backup']['recent'] && ! $backupFirst) {
            throw new ApiException(409, 'backup_required', __('system.upgrade.backup_required'));
        }
        if ($backupFirst && ! BackupCrypto::configured()) {
            throw new ApiException(409, 'backup_not_configured', __('system.backup.not_configured'));
        }

        $lock = Cache::lock('sitex:system:upgrade', 10);
        if (! $lock->get()) {
            throw ApiException::conflict('upgrade_in_progress', __('system.upgrade.in_progress'));
        }
        try {
            if (UpgradeRun::query()->whereIn('status', ['queued', 'running'])->exists()) {
                throw ApiException::conflict('upgrade_in_progress', __('system.upgrade.in_progress'));
            }
            $run = new UpgradeRun;
            $run->forceFill([
                'from_version' => $status['current_version'],
                'to_version' => $status['target_version'],
                'status' => 'queued',
                'requested_by' => (string) $request->user()?->getAuthIdentifier(),
            ])->save();
        } finally {
            $lock->release();
        }
        Audit::record('system.upgrade_started', $run, ['from' => $run->from_version, 'to' => $run->to_version, 'backup_first' => $backupFirst], null, (string) $request->user()?->getAuthIdentifier(), 'staff');
        RunUpgrade::dispatch($run->id, $backupFirst);

        return new JsonResponse(['data' => ['id' => $run->id, 'status' => $run->refresh()->status]], 202);
    }

    public function backups(SystemSettings $settings): JsonResponse
    {
        return new JsonResponse(['data' => [
            'configured' => BackupCrypto::configured(),
            'offsite_disk' => (string) config('sitex.system.backup.disk') ?: null,
            'retention_days' => (int) config('sitex.system.backup.retention_days', 14),
            'schedule' => $settings->publicGroup('backup'),
            'items' => SystemBackup::query()->orderByDesc('created_at')->limit(30)->get()->map(fn (SystemBackup $b) => [
                'id' => $b->id, 'status' => $b->status, 'trigger' => $b->trigger, 'file_name' => $b->file_name,
                'size_bytes' => $b->size_bytes, 'sha256' => $b->sha256, 'contents' => $b->contents ?? [],
                'offsite_status' => $b->offsite_status, 'verify_status' => $b->verify_status,
                'verified_at' => $b->verified_at?->toIso8601ZuluString(), 'error' => $b->error,
                'started_at' => $b->started_at?->toIso8601ZuluString(), 'finished_at' => $b->finished_at?->toIso8601ZuluString(),
            ])->all(),
        ]]);
    }

    public function startBackup(ReauthRequest $request, BackupManager $manager): JsonResponse
    {
        if (! BackupCrypto::configured()) {
            throw new ApiException(409, 'backup_not_configured', __('system.backup.not_configured'));
        }
        if (SystemBackup::query()->where('status', 'running')->where('started_at', '>', now()->subHours(2))->exists()) {
            throw ApiException::conflict('backup_in_progress', __('system.backup.in_progress'));
        }
        $backup = $manager->start('manual', (string) $request->user()?->getAuthIdentifier());
        Audit::record('system.backup_started', $backup, [], null, (string) $request->user()?->getAuthIdentifier(), 'staff');
        RunBackup::dispatch($backup->id);

        return new JsonResponse(['data' => ['id' => $backup->id, 'status' => $backup->refresh()->status]], 202);
    }

    public function verifyBackup(string $backup): JsonResponse
    {
        $b = SystemBackup::query()->where('status', 'succeeded')->findOrFail($backup);
        VerifyBackup::dispatch($b->id);

        return new JsonResponse(['data' => ['queued' => true]], 202);
    }
}
