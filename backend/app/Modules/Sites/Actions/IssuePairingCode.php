<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SitePairing;
use SiteXPost\Modules\Sites\Enums\SiteStatus;

/**
 * Issues a one-time pairing code (returned once, only its HMAC is stored).
 * A new code invalidates previous unused codes of the site.
 */
final class IssuePairingCode
{
    /**
     * @return array{0: string, 1: Carbon}
     */
    public function handle(Site $site, User $user): array
    {
        if ($site->status->isPaired()) {
            throw ApiException::conflict('already_connected', __('sites.errors.already_connected'));
        }

        return DB::transaction(function () use ($site, $user): array {
            if ($site->status === SiteStatus::Revoked) {
                $site->forceFill(['status' => SiteStatus::PendingPairing])->save();
            }

            $result = $this->create($site, $user);
            Audit::record('site.pairing_code_issued', $site);

            return $result;
        });
    }

    /**
     * @return array{0: string, 1: Carbon}
     */
    public function create(Site $site, User $user): array
    {
        $site->pairings()->whereNull('used_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);

        $code = SitePairing::generateCode();
        $expiresAt = now()->addMinutes((int) config('sitex.connector.pairing_ttl_minutes', 15));

        $pairing = new SitePairing;
        $pairing->forceFill([
            'site_id' => $site->id,
            'code_hash' => SitePairing::hashCode($code),
            'expires_at' => $expiresAt,
            'created_by' => $user->id,
        ])->save();

        return [$code, $expiresAt];
    }
}
