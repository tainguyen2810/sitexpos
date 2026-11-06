<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Sites\Actions;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * Builds the installable WordPress plugin zip with this Cloud's public API URL
 * as the plugin's default SITEX_POST_CLOUD_URL, so a customer only uploads the
 * zip — no wp-config.php edit. The plugin contains no secret: pairing still
 * needs a one-time code issued to a workspace member.
 */
final class BuildPluginPackage
{
    /** Exact default line in sitex-post.php that gets rewritten. */
    public const DEFAULT_LINE = "define( 'SITEX_POST_CLOUD_URL', 'https://api.sitexpost.com' );";

    /**
     * @return array{path: string, filename: string, version: string}
     */
    public function handle(): array
    {
        $source = rtrim((string) config('sitex.plugin.source_path'), '/');
        $main = $source.'/sitex-post.php';
        if (! is_file($main)) {
            throw new RuntimeException('WordPress plugin source not found.');
        }

        $mainCode = (string) file_get_contents($main);
        if (substr_count($mainCode, self::DEFAULT_LINE) !== 1) {
            throw new RuntimeException('Plugin default Cloud URL line not found.');
        }
        $mainCode = str_replace(self::DEFAULT_LINE, $this->cloudUrlLine(), $mainCode);

        $version = preg_match('/^\s*\*\s*Version:\s*([0-9A-Za-z.\-]+)/m', $mainCode, $m) === 1 ? $m[1] : 'dev';

        $path = tempnam(sys_get_temp_dir(), 'sxpplugin');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the plugin zip.');
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->isLink() || $file->getFilename() === '.DS_Store') {
                continue;
            }
            $relative = 'sitex-post/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($source))), '/');
            if ($file->getPathname() === $main) {
                $zip->addFromString($relative, $mainCode);
            } else {
                $zip->addFile($file->getPathname(), $relative);
            }
        }
        $zip->close();

        return ['path' => $path, 'filename' => 'sitex-post-'.$version.'.zip', 'version' => $version];
    }

    private function cloudUrlLine(): string
    {
        $url = rtrim((string) config('sitex.connector.public_url'), '/');
        // Only a plain http(s) origin/path may be written into PHP source.
        if (preg_match('#^https?://[A-Za-z0-9.\-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~\-/]*)?$#', $url) !== 1) {
            throw new RuntimeException('Invalid connector public URL.');
        }

        return "define( 'SITEX_POST_CLOUD_URL', '".$url."' );";
    }
}
