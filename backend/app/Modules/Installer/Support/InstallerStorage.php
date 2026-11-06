<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Support;

use RuntimeException;

/**
 * Private files of the installer (setup token hash, progress, pending config,
 * lock). The directory lives under storage/ (never web-served) and every file
 * is written atomically with 0600 permissions.
 */
final class InstallerStorage
{
    public function dir(): string
    {
        return rtrim((string) config('sitex.installer.state_path'), '/');
    }

    public function path(string $name): string
    {
        return $this->dir().'/'.$name;
    }

    public function exists(string $name): bool
    {
        return is_file($this->path($name));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readJson(string $name): ?array
    {
        $path = $this->path($name);
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function writeJson(string $name, array $data): void
    {
        $this->write($name, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function read(string $name): ?string
    {
        $path = $this->path($name);

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    public function write(string $name, string $contents): void
    {
        $this->ensureDir();
        $path = $this->path($name);
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $old = umask(0077);
        try {
            if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write installer file '.$name);
            }
            @chmod($tmp, 0600);
            if (! rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Cannot write installer file '.$name);
            }
        } finally {
            umask($old);
        }
    }

    public function delete(string $name): void
    {
        $path = $this->path($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Exclusive, non-blocking process lock (one installation run at a time).
     *
     * @return resource|null
     */
    public function lock(string $name)
    {
        $this->ensureDir();
        $handle = fopen($this->path($name), 'c');
        if ($handle === false) {
            return null;
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param  resource|null  $handle
     */
    public function unlock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function writable(): bool
    {
        try {
            $this->ensureDir();
        } catch (RuntimeException) {
            return false;
        }

        return is_writable($this->dir());
    }

    private function ensureDir(): void
    {
        $dir = $this->dir();
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create installer directory.');
        }
        // Defence in depth if storage/ were ever exposed by a misconfigured web root.
        if (! is_file($dir.'/.htaccess')) {
            @file_put_contents($dir.'/.htaccess', "Require all denied\n");
        }
    }
}
