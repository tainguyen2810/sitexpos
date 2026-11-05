<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Media\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Private storage for media bytes: `media/<workspace>/<asset>/<kind>.<ext>`
 * on the disk set by SITEX_MEDIA_DISK (default `local`, never public).
 * Files are served only through signed, expiring URLs.
 */
final class MediaStorage
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('sitex.media.disk', 'local'));
    }

    public function path(string $workspaceId, string $assetId, string $kind, string $ext): string
    {
        return 'media/'.$workspaceId.'/'.$assetId.'/'.$kind.'.'.$ext;
    }

    public function put(string $path, string $bytes): void
    {
        $this->disk()->put($path, $bytes);
    }

    public function get(string $path): ?string
    {
        return $this->disk()->exists($path) ? $this->disk()->get($path) : null;
    }

    public function deleteAsset(string $workspaceId, string $assetId): void
    {
        $this->disk()->deleteDirectory('media/'.$workspaceId.'/'.$assetId);
    }
}
