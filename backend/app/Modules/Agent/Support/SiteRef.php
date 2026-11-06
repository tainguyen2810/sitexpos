<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Agent\Support;

use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Sites\Contracts\SiteDirectory;

/** Resolves a site id inside the current tenant (another tenant's id → 404). */
final class SiteRef
{
    /**
     * @return array{id: string, name: string, url: string, language: string, status: string, paired: bool, woocommerce: bool, last_synced_at: string|null}
     */
    public static function resolve(string $siteId): array
    {
        $site = preg_match('/^[0-9a-z]{26}$/i', $siteId) === 1 ? app(SiteDirectory::class)->find($siteId) : null;

        return $site ?? throw ApiException::notFound();
    }
}
