<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Support;

use Illuminate\Encryption\Encrypter;
use Throwable;

/**
 * Configuration collected by the wizard, kept on the server between steps
 * (never in the browser). Secret fields (database/Redis/SMTP passwords, the
 * admin password hash) are encrypted with a random installer key stored next
 * to it with 0600 permissions — the same protection level as the final .env
 * file. Both files are deleted when the installation completes.
 */
final class PendingConfig
{
    private const FILE = 'pending.json';

    private const KEY_FILE = 'pending.key';

    public const SECRET_FIELDS = ['database.password', 'redis.password', 'mail.password', 'admin.password_hash'];

    public function __construct(private readonly InstallerStorage $storage) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        /** @var array<string, array<string, mixed>> $data */
        $data = $this->storage->readJson(self::FILE) ?? [];

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function section(string $section): array
    {
        $data = $this->all()[$section] ?? [];
        foreach ($data as $field => $value) {
            if (in_array($section.'.'.$field, self::SECRET_FIELDS, true) && is_string($value) && $value !== '') {
                $data[$field] = $this->decrypt($value);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function put(string $section, array $values): void
    {
        foreach ($values as $field => $value) {
            if (in_array($section.'.'.$field, self::SECRET_FIELDS, true) && is_string($value) && $value !== '') {
                $values[$field] = $this->encrypter()->encryptString($value);
            }
        }
        $all = $this->all();
        $all[$section] = $values;
        $this->storage->writeJson(self::FILE, $all);
    }

    public function has(string $section): bool
    {
        return isset($this->all()[$section]);
    }

    /**
     * Non-secret view for the wizard UI (secrets → `<field>_set: bool`).
     *
     * @return array<string, array<string, mixed>>
     */
    public function publicView(): array
    {
        $out = [];
        foreach ($this->all() as $section => $values) {
            foreach ($values as $field => $value) {
                if (in_array($section.'.'.$field, self::SECRET_FIELDS, true)) {
                    $out[$section][$field.'_set'] = is_string($value) && $value !== '';
                } else {
                    $out[$section][$field] = $value;
                }
            }
        }

        return $out;
    }

    public function clear(): void
    {
        $this->storage->delete(self::FILE);
        $this->storage->delete(self::KEY_FILE);
    }

    private function decrypt(string $value): ?string
    {
        try {
            return $this->encrypter()->decryptString($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function encrypter(): Encrypter
    {
        $key = $this->storage->read(self::KEY_FILE);
        if ($key === null || strlen(base64_decode($key, true) ?: '') !== 32) {
            $key = base64_encode(random_bytes(32));
            $this->storage->write(self::KEY_FILE, $key);
        }

        return new Encrypter((string) base64_decode($key, true), 'aes-256-cbc');
    }
}
