<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Http\Controllers;

use SiteXPost\Modules\Sites\Actions\BuildPluginPackage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public download of the WordPress plugin, pre-configured with this Cloud's URL
 * (API-CONTRACTS §Sites). Public on purpose: the plugin holds no secret.
 */
final class PluginDownloadController
{
    public function __invoke(BuildPluginPackage $build): BinaryFileResponse
    {
        $package = $build->handle();

        return response()
            ->download($package['path'], $package['filename'], [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend();
    }
}
