<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Tenancy\TenantContext;
use SiteXPost\Modules\Sites\Domain\Site;
use SiteXPost\Modules\Sites\Domain\SitePairing;

/**
 * Finds the pairing for (code, site_url) presented by the plugin. Runs
 * without a tenant context (the caller is the anonymous plugin), so every
 * query here is a reviewed bypass of the workspace scope.
 *
 * Brute force: each failed guess against a site's URL increments the attempt
 * counter of that site's open codes (max 5, then the code is dead) on top of
 * the per-IP rate limit.
 */
final class ResolvePairing
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(string $code, string $siteUrl): SitePairing
    {
        return $this->context->bypass(function () use ($code, $siteUrl): SitePairing {
            $urlHash = Site::hashUrl($siteUrl);
            $pairing = SitePairing::query()->with('site')->where('code_hash', SitePairing::hashCode($code))->first();

            if ($pairing === null || ! $pairing->isUsable()) {
                $this->countFailedAttempt($urlHash);

                throw new ApiException(422, 'invalid_pairing_code', __('sites.errors.invalid_pairing_code'));
            }

            if ($pairing->site->url_hash !== $urlHash) {
                $pairing->increment('attempts');

                throw ApiException::conflict('site_url_mismatch', __('sites.errors.site_url_mismatch', ['url' => $pairing->site->url]));
            }

            return $pairing;
        });
    }

    private function countFailedAttempt(string $urlHash): void
    {
        $siteIds = Site::query()->where('url_hash', $urlHash)->pluck('id');
        if ($siteIds->isEmpty()) {
            return;
        }

        SitePairing::query()
            ->whereIn('site_id', $siteIds)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->increment('attempts');
    }
}
