<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Support;

/**
 * One-time setup token proving control of the server (INSTALLER-SECURITY.md).
 * Only its SHA-256 is kept in `token.json`; the plaintext is shown once by
 * `php artisan sitex:install:token`, or — when the operator opens /install
 * without having run the CLI — written to `setup-token.txt` (0600) inside the
 * private installer directory so only someone with file access to the server
 * (aaPanel file manager / SSH) can read it.
 */
final class SetupToken
{
    public const FILE_PLAIN = 'setup-token.txt';

    private const FILE_HASH = 'token.json';

    public function __construct(private readonly InstallerStorage $storage) {}

    /**
     * Creates a new token (invalidating any previous one) and returns its plaintext.
     */
    public function issue(bool $writePlainFile): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $ttl = max(1, (int) config('sitex.installer.token_ttl_hours', 24));
        $this->storage->writeJson(self::FILE_HASH, [
            'hash' => hash('sha256', $token),
            'created_at' => now()->toIso8601ZuluString(),
            'expires_at' => now()->addHours($ttl)->toIso8601ZuluString(),
        ]);

        if ($writePlainFile) {
            $this->storage->write(self::FILE_PLAIN, $token."\n");
        } else {
            $this->storage->delete(self::FILE_PLAIN);
        }

        return $token;
    }

    /**
     * Makes sure a valid token exists so the operator can find it on disk.
     */
    public function ensureIssued(): void
    {
        if (! $this->active()) {
            $this->issue(true);
        }
    }

    public function active(): bool
    {
        $data = $this->storage->readJson(self::FILE_HASH);

        return is_array($data) && is_string($data['hash'] ?? null)
            && strtotime((string) ($data['expires_at'] ?? '')) > now()->getTimestamp();
    }

    public function verify(?string $token): bool
    {
        if (! is_string($token) || $token === '' || strlen($token) > 200) {
            return false;
        }
        $data = $this->storage->readJson(self::FILE_HASH);
        if (! is_array($data) || ! is_string($data['hash'] ?? null)) {
            return false;
        }
        if (strtotime((string) ($data['expires_at'] ?? '')) <= now()->getTimestamp()) {
            return false;
        }

        return hash_equals($data['hash'], hash('sha256', $token));
    }

    public function revoke(): void
    {
        $this->storage->delete(self::FILE_HASH);
        $this->storage->delete(self::FILE_PLAIN);
    }
}
