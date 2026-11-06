<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Support;

use RuntimeException;

/**
 * Writes .env atomically. Existing keys not managed by the installer are kept,
 * the previous file is backed up into the private installer directory, and an
 * existing APP_KEY is NEVER replaced (encrypted data would become unreadable).
 */
final class EnvWriter
{
    public function __construct(private readonly InstallerStorage $storage) {}

    public function path(): string
    {
        $configured = (string) config('sitex.installer.env_path');

        return $configured !== '' ? $configured : app()->environmentFilePath();
    }

    public function currentAppKey(): ?string
    {
        $values = $this->parse((string) @file_get_contents($this->path()));
        $key = $values['APP_KEY'] ?? '';

        return $key !== '' ? $key : null;
    }

    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /**
     * @param  array<string, string|int|bool|null>  $values
     */
    public function write(array $values): void
    {
        $path = $this->path();
        $existing = is_file($path) ? (string) file_get_contents($path) : null;
        $template = $existing ?? (string) @file_get_contents(base_path('.env.example'));

        if ($existing !== null) {
            $this->storage->write('env-backup-'.now()->format('Ymd-His').'.env', $existing);
            $current = $this->parse($existing)['APP_KEY'] ?? '';
            if ($current !== '') {
                $values['APP_KEY'] = $current; // never rotate an existing key
            }
        }

        $content = $this->merge($template, $values);
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $old = umask(0027);
        try {
            if (file_put_contents($tmp, $content, LOCK_EX) === false) {
                throw new RuntimeException('env_not_writable');
            }
            @chmod($tmp, 0640);
            if (! rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('env_not_writable');
            }
        } finally {
            umask($old);
        }
    }

    /**
     * @param  array<string, string|int|bool|null>  $values
     */
    public function merge(string $template, array $values): string
    {
        $lines = preg_split('/\r\n|\n/', $template) ?: [];
        $done = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $m) && array_key_exists($m[1], $values)) {
                if (str_starts_with(ltrim($line), '#') && isset($done[$m[1]])) {
                    continue;
                }
                if (isset($done[$m[1]])) {
                    $lines[$i] = '# (duplicate removed by installer) '.$line;

                    continue;
                }
                $lines[$i] = $m[1].'='.self::quote($values[$m[1]]);
                $done[$m[1]] = true;
            }
        }
        $missing = array_diff_key($values, $done);
        if ($missing !== []) {
            $lines[] = '';
            $lines[] = '# Written by the SiteX Post installer';
            foreach ($missing as $k => $v) {
                $lines[] = $k.'='.self::quote($v);
            }
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    public static function quote(string|int|bool|null $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9_.:\/@+\-,]+$/', $value) === 1) {
            return $value;
        }

        // Double quotes; escape backslash, quote and "$" (dotenv variable expansion).
        return '"'.str_replace(['\\', '"', '$', "\n", "\r"], ['\\\\', '\\"', '\\$', '', ''], $value).'"';
    }

    /**
     * @return array<string, string>
     */
    public function parse(string $content): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n/', $content) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                $v = trim($m[2]);
                if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
                    $v = substr($v, 1, -1);
                }
                $out[$m[1]] = $v;
            }
        }

        return $out;
    }
}
