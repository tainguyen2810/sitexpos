<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use SiteXPost\Modules\Sites\Domain\SiteActivityLog;
use SiteXPost\Modules\Sites\Domain\SiteCredential;
use SiteXPost\Modules\Sites\Enums\SiteStatus;

/**
 * Signed heartbeat from the plugin (after pairing, daily cron, settings save).
 */
final class RecordHeartbeat
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(SiteCredential $credential, array $data): void
    {
        $site = $credential->site;

        $site->forceFill([
            'wp_version' => self::str($data['wp_version'] ?? null) ?? $site->wp_version,
            'php_version' => self::str($data['php_version'] ?? null) ?? $site->php_version,
            'plugin_version' => self::str($data['plugin_version'] ?? null) ?? $site->plugin_version,
            'last_seen_at' => now(),
        ]);
        if ($site->status === SiteStatus::PendingPairing) {
            $site->status = SiteStatus::Connected;
        }
        $site->save();

        $credential->forceFill(['last_used_at' => now()])->saveQuietly();

        $log = new SiteActivityLog;
        $log->forceFill([
            'site_id' => $site->id, 'direction' => 'in', 'operation' => 'heartbeat', 'level' => 'info',
            'http_status' => 200, 'request_id' => request()->attributes->get('request_id'),
        ])->save();
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 32) : null;
    }
}
