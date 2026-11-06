<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Sites\Domain\Site;

/**
 * Removes the site and all its connector data (keys, index, mappings).
 * A connected site is revoked first so its key can never be used again.
 * Content on WordPress is never deleted.
 */
final class DeleteSite
{
    public function __construct(private readonly RevokeSite $revoke) {}

    public function handle(Site $site): void
    {
        if ($site->status->isPaired()) {
            $this->revoke->handle($site);
        }

        Audit::record('site.deleted', $site, ['url' => $site->url]);
        $site->delete();
    }
}
