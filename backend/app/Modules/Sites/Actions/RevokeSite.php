<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use Illuminate\Support\Facades\DB;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Sites\Connector\ConnectorException;
use SiteXPost\Modules\Sites\Connector\WordPressClient;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Enums\CredentialStatus;
use SiteXPost\Modules\Sites\Enums\SiteStatus;

/**
 * Disconnect. All keys of the site are revoked immediately in the database,
 * so every later request in either direction fails, whether or not the
 * plugin could be told. From Cloud we first try (best effort) to tell the
 * plugin to wipe its key.
 */
final class RevokeSite
{
    public function __construct(private readonly WordPressClient $client) {}

    /**
     * @return array{plugin_notified: bool}
     */
    public function handle(Site $site, string $by = 'cloud'): array
    {
        $notified = false;
        if ($by === 'cloud' && $site->status->isPaired()) {
            try {
                $this->client->call($site, 'POST', '/sitex-post/v1/disconnect', [], ['reason' => 'revoked_from_cloud']);
                $notified = true;
            } catch (ConnectorException) {
                $notified = false; // the key is revoked below anyway
            }
        }

        DB::transaction(function () use ($site, $by, $notified): void {
            $site->credentials()->where('status', '!=', CredentialStatus::Revoked->value)
                ->update(['status' => CredentialStatus::Revoked, 'revoked_at' => now(), 'grace_until' => null]);
            $site->pairings()->whereNull('used_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);

            $site->forceFill([
                'status' => SiteStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by' => $by,
            ])->save();

            Audit::record('site.revoked', $site, ['by' => $by, 'plugin_notified' => $notified], actorType: $by === 'wordpress' ? 'system' : 'user');
        });

        return ['plugin_notified' => $notified];
    }
}
