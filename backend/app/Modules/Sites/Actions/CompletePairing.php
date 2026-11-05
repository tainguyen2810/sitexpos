<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Sites\Connector\Signer;
use SiteXPost\Modules\Sites\Domain\SiteCredential;
use SiteXPost\Modules\Sites\Domain\SitePairing;
use SiteXPost\Modules\Sites\Enums\CredentialStatus;
use SiteXPost\Modules\Sites\Enums\SiteStatus;
use SiteXPost\Modules\Sites\Jobs\HealthCheckSite;
use SiteXPost\Modules\Workspace\Domain\Workspace;

/**
 * Final pairing step, called by the plugin after the WordPress admin has
 * reviewed the request and confirmed it. Issues a fresh per-site secret
 * (returned exactly once, over HTTPS, to the plugin) and invalidates the code.
 */
final class CompletePairing
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $payload  validated PairRequest data
     * @return array<string, mixed>
     */
    public function handle(SitePairing $pairing, array $payload): array
    {
        /** @var Workspace $workspace */
        $workspace = Workspace::query()->findOrFail($pairing->workspace_id);

        return $this->context->runAs($workspace, null, function () use ($pairing, $payload, $workspace): array {
            [$credential, $secret, $site] = DB::transaction(function () use ($pairing, $payload): array {
                /** @var SitePairing $locked */
                $locked = SitePairing::query()->with('site')->lockForUpdate()->findOrFail($pairing->id);
                if (! $locked->isUsable()) {
                    throw new ApiException(422, 'invalid_pairing_code', __('sites.errors.invalid_pairing_code'));
                }
                $locked->forceFill(['used_at' => now()])->save();

                $site = $locked->site;
                $site->credentials()->whereIn('status', [CredentialStatus::Active, CredentialStatus::Rotated])
                    ->update(['status' => CredentialStatus::Revoked, 'revoked_at' => now()]);

                $secret = SiteCredential::generateSecret();
                $credential = new SiteCredential;
                $credential->forceFill(['site_id' => $site->id, 'key_id' => (string) Str::ulid(), 'status' => CredentialStatus::Active]);
                $credential->secret = $secret;
                $credential->save();

                /** @var array<string, mixed> $env */
                $env = (array) ($payload['environment'] ?? []);
                /** @var array<string, mixed> $author */
                $author = (array) ($payload['publish_author'] ?? []);

                $site->forceFill([
                    'status' => SiteStatus::Connected,
                    'wp_version' => $env['wp_version'] ?? null,
                    'php_version' => $env['php_version'] ?? null,
                    'plugin_version' => $env['plugin_version'] ?? null,
                    'publish_author_wp_id' => $author['wp_user_id'] ?? null,
                    'publish_author_name' => $author['display_name'] ?? null,
                    'capabilities' => array_merge($site->capabilities ?? [], [
                        'rest_url' => $payload['rest_url'] ?? null,
                    ]),
                    'connected_at' => now(),
                    'last_seen_at' => now(),
                    'revoked_at' => null,
                    'revoked_by' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                Audit::record('site.connected', $site, [
                    'key_id' => $credential->key_id,
                    'wp_admin' => $payload['confirmed_by']['display_name'] ?? null,
                    'publish_author_wp_id' => $site->publish_author_wp_id,
                ], actorType: 'system');

                return [$credential, $secret, $site];
            });

            // Verify the reverse direction (Cloud → WP) once the plugin has stored the key.
            HealthCheckSite::dispatch($site->id, null)->afterCommit()->delay(now()->addSeconds(5));

            return [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'workspace_name' => $workspace->name,
                'key_id' => $credential->key_id,
                'secret' => $secret,
                'signature_version' => Signer::VERSION,
                'cloud_api_base' => config('sitex.connector.public_url').'/connector/v1',
            ];
        });
    }
}
