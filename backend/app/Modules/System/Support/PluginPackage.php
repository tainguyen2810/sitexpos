<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Support;

/**
 * The WordPress connector plugin ZIP built by wordpress-plugin/scripts/build-plugin.sh
 * (dist/sitex-post-<version>.zip). Only files matching that exact name pattern
 * in the configured directory can be served (no path input from users).
 */
final class PluginPackage
{
    /**
     * @return array{name: string, version: ?string, available: bool, file_name: ?string, size_bytes: ?int, source_version: ?string}
     */
    public function info(): array
    {
        $file = $this->latestZip();

        return [
            'name' => 'SiteX Post',
            'version' => $file !== null ? $this->versionFromName(basename($file)) : null,
            'available' => $file !== null,
            'file_name' => $file !== null ? basename($file) : null,
            'size_bytes' => $file !== null ? (int) filesize($file) : null,
            'source_version' => $this->sourceVersion(),
        ];
    }

    public function latestZip(): ?string
    {
        $dir = rtrim((string) config('sitex.system.plugin_zip_dir'), '/');
        $files = glob($dir.'/sitex-post-*.zip') ?: [];
        $files = array_values(array_filter($files, fn (string $f) => preg_match('/^sitex-post-\d+\.\d+\.\d+\.zip$/', basename($f)) === 1));
        if ($files === []) {
            return null;
        }
        usort($files, fn (string $a, string $b) => version_compare((string) $this->versionFromName(basename($b)), (string) $this->versionFromName(basename($a))));

        return $files[0];
    }

    private function versionFromName(string $name): ?string
    {
        return preg_match('/^sitex-post-(\d+\.\d+\.\d+)\.zip$/', $name, $m) === 1 ? $m[1] : null;
    }

    private function sourceVersion(): ?string
    {
        $main = base_path('../wordpress-plugin/sitex-post/sitex-post.php');
        if (! is_file($main)) {
            return null;
        }

        return preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', (string) file_get_contents($main), $m) === 1 ? $m[1] : null;
    }
}
