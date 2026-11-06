<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Backup;

use RuntimeException;

/**
 * Streaming AES-256-GCM file encryption for backups (key ≠ APP_KEY, so a
 * leaked backup cannot be read with the application key and a lost server
 * does not take the backup key with it).
 *
 * Format: "SXPBK1" | 12-byte base nonce | repeated [4-byte BE length | ciphertext | 16-byte tag].
 * Chunk i uses nonce = base nonce with the last 4 bytes XOR i; a final empty
 * chunk marks the end so truncation is detected.
 */
final class BackupCrypto
{
    private const MAGIC = 'SXPBK1';

    private const CHUNK = 1048576;

    public static function key(): string
    {
        $raw = (string) config('sitex.system.backup.encryption_key');
        $raw = str_starts_with($raw, 'base64:') ? substr($raw, 7) : $raw;
        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('backup_key_not_configured');
        }

        return $key;
    }

    public static function configured(): bool
    {
        try {
            self::key();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function encryptFile(string $in, string $out, ?string $key = null): void
    {
        $key ??= self::key();
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if ($src === false || $dst === false) {
            throw new RuntimeException('backup_io_error');
        }
        $nonce = random_bytes(12);
        fwrite($dst, self::MAGIC.$nonce);
        $i = 0;
        while (true) {
            $chunk = (string) fread($src, self::CHUNK);
            $tag = '';
            $cipher = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce($nonce, $i), $tag, self::MAGIC.pack('N', $i).($chunk === '' ? 'END' : ''));
            if ($cipher === false) {
                throw new RuntimeException('backup_encrypt_failed');
            }
            fwrite($dst, pack('N', strlen($cipher)).$cipher.$tag);
            $i++;
            if ($chunk === '') {
                break;
            }
        }
        fclose($src);
        fclose($dst);
    }

    public static function decryptFile(string $in, string $out, ?string $key = null): void
    {
        $key ??= self::key();
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if ($src === false || $dst === false) {
            throw new RuntimeException('backup_io_error');
        }
        if (fread($src, 6) !== self::MAGIC) {
            throw new RuntimeException('backup_bad_format');
        }
        $nonce = (string) fread($src, 12);
        $i = 0;
        $ended = false;
        while (! feof($src)) {
            $lenRaw = fread($src, 4);
            if ($lenRaw === false || strlen($lenRaw) < 4) {
                break;
            }
            $len = (int) unpack('N', $lenRaw)[1];
            $cipher = $len > 0 ? (string) fread($src, $len) : '';
            $tag = (string) fread($src, 16);
            $isEnd = $len === 0;
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce($nonce, $i), $tag, self::MAGIC.pack('N', $i).($isEnd ? 'END' : ''));
            if ($plain === false) {
                throw new RuntimeException('backup_auth_failed');
            }
            fwrite($dst, $plain);
            $i++;
            if ($isEnd) {
                $ended = true;
                break;
            }
        }
        fclose($src);
        fclose($dst);
        if (! $ended) {
            throw new RuntimeException('backup_truncated');
        }
    }

    private static function nonce(string $base, int $i): string
    {
        return substr($base, 0, 8).(substr($base, 8, 4) ^ pack('N', $i));
    }
}
