<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Connector\WordPressClient;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteHealthCheck;
use SiteXPost\Modules\Sites\Enums\SiteStatus;

/**
 * Signed GET /health: verifies the plugin is reachable, the key is valid in
 * both directions and reports WordPress/plugin versions and capabilities.
 */
final class RunHealthCheck
{
    public function __construct(private readonly WordPressClient $client) {}

    public function handle(Site $site): SiteHealthCheck
    {
        $started = microtime(true);
        $check = new SiteHealthCheck;
        $check->forceFill(['site_id' => $site->id, 'checked_at' => now()]);

        if (! $site->status->isPaired()) {
            $check->forceFill(['ok' => false, 'error_code' => 'not_connected'])->save();

            return $check;
        }

        try {
            $data = $this->client->call($site, 'GET', '/sitex-post/v1/health');
            $issues = $this->issues($data);

            $check->forceFill([
                'ok' => $issues === [],
                'http_status' => 200,
                'error_code' => $issues === [] ? null : $issues[0],
                'details' => ['issues' => $issues, 'versions' => $data['versions'] ?? null],
            ]);

            $wasDegraded = $site->status === SiteStatus::Degraded;
            $site->forceFill([
                'status' => SiteStatus::Connected,
                'wp_version' => self::str($data['versions']['wordpress'] ?? null) ?? $site->wp_version,
                'php_version' => self::str($data['versions']['php'] ?? null) ?? $site->php_version,
                'plugin_version' => self::str($data['versions']['plugin'] ?? null) ?? $site->plugin_version,
                'capabilities' => array_merge($site->capabilities ?? [], [
                    'rest_url' => $site->capabilities['rest_url'] ?? null,
                    'features' => $data['features'] ?? [],
                    'author' => $data['author'] ?? null,
                    'limits' => $data['limits'] ?? [],
                    'site' => is_array($data['site'] ?? null) ? array_intersect_key($data['site'], array_flip(['home_url', 'locale', 'timezone'])) : ($site->capabilities['site'] ?? null),
                    'issues' => $issues,
                ]),
                'last_seen_at' => now(),
                'last_error_code' => $issues === [] ? null : $issues[0],
                'last_error_message' => $issues === [] ? null : __('sites.issues.'.$issues[0]),
            ]);
            if ($wasDegraded) {
                Audit::record('site.recovered', $site, actorType: 'system');
            }
        } catch (ConnectorException $e) {
            $check->forceFill(['ok' => false, 'http_status' => $e->httpStatus, 'error_code' => $e->errorCode]);
            $site->forceFill([
                'status' => SiteStatus::Degraded,
                'last_error_code' => $e->errorCode,
                'last_error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }

        $check->latency_ms = (int) round((microtime(true) - $started) * 1000);
        $check->save();
        $site->forceFill(['last_health_at' => now()])->save();

        return $check;
    }

    /**
     * Problems that make publishing impossible even though the plugin answers.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function issues(array $data): array
    {
        $issues = [];
        $author = is_array($data['author'] ?? null) ? $data['author'] : [];
        if (($author['exists'] ?? false) !== true) {
            $issues[] = 'author_missing';
        } elseif (($author['can_publish'] ?? false) !== true) {
            $issues[] = 'author_cannot_publish';
        } elseif (($author['can_upload'] ?? false) !== true) {
            $issues[] = 'author_cannot_upload';
        }

        $plugin = self::str($data['versions']['plugin'] ?? null);
        if ($plugin !== null && version_compare($plugin, (string) config('sitex.connector.min_plugin_version'), '<')) {
            $issues[] = 'plugin_outdated';
        }

        return $issues;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 32) : null;
    }
}
