<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Connector\WordPressClient;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SiteCredential;
use SiteXPost\Modules\Sites\Enums\CredentialStatus;

/**
 * Key rotation: the new secret is delivered to the plugin in a request signed
 * with the current key. Only after the plugin confirms (signed response) is
 * the new key activated; the old key keeps working for a short overlap window
 * so in-flight requests do not fail.
 */
final class RotateCredentials
{
    public function __construct(private readonly WordPressClient $client) {}

    public function handle(Site $site): SiteCredential
    {
        $current = $site->activeCredential();
        if (! $site->status->isPaired() || $current === null) {
            throw ApiException::conflict('not_connected', __('sites.errors.not_connected'));
        }

        $secret = SiteCredential::generateSecret();
        $keyId = (string) Str::ulid();

        try {
            $this->client->call($site, 'POST', '/sitex-post/v1/credentials/rotate', [], [
                'key_id' => $keyId,
                'secret' => $secret,
                'grace_seconds' => (int) config('sitex.connector.rotation_grace_minutes', 10) * 60,
            ], $current);
        } catch (ConnectorException $e) {
            // The answer may have been lost after WordPress already switched
            // keys (timeout, unreadable response). Probe with the new key: if
            // WordPress accepts it, the rotation happened and Cloud must follow,
            // otherwise both sides would hold different keys.
            if (! $this->wordPressAcceptsKey($site, $keyId, $secret)) {
                throw new ApiException(502, 'rotation_failed', __('sites.errors.rotation_failed', ['reason' => $e->getMessage()]));
            }
        }

        return DB::transaction(function () use ($site, $current, $secret, $keyId): SiteCredential {
            $current->forceFill([
                'status' => CredentialStatus::Rotated,
                'rotated_at' => now(),
                'grace_until' => now()->addMinutes((int) config('sitex.connector.rotation_grace_minutes', 10)),
            ])->save();

            $credential = new SiteCredential;
            $credential->forceFill(['site_id' => $site->id, 'key_id' => $keyId, 'status' => CredentialStatus::Active]);
            $credential->secret = $secret;
            $credential->save();

            Audit::record('site.keys_rotated', $site, ['old_key_id' => $current->key_id, 'new_key_id' => $keyId]);

            return $credential;
        });
    }

    private function wordPressAcceptsKey(Site $site, string $keyId, string $secret): bool
    {
        $probe = new SiteCredential;
        $probe->forceFill(['site_id' => $site->id, 'key_id' => $keyId, 'status' => CredentialStatus::Active]);
        $probe->secret = $secret;

        try {
            $this->client->call($site, 'GET', '/sitex-post/v1/health', [], null, $probe);

            return true;
        } catch (ConnectorException) {
            return false;
        }
    }
}
