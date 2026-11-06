<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use SiteXPost\Modules\System\Domain\SystemSetting;
use Throwable;

/**
 * Platform settings edited from the Setup Wizard / System pages. Only keys in
 * DEFINITIONS can be stored; secrets are encrypted with APP_KEY (Crypt), are
 * write-only through the API (the API only says whether one is set) and are
 * never logged.
 */
final class SystemSettings
{
    private const CACHE_KEY = 'sitex:system_settings:v1';

    /**
     * key => [default, secret]
     *
     * @var array<string, array{0: mixed, 1: bool}>
     */
    public const DEFINITIONS = [
        // General
        'general.brand_name' => ['SiteX Post', false],
        'general.logo_path' => [null, false],
        'general.support_email' => [null, false],
        'general.default_locale' => ['vi', false],
        'general.default_timezone' => ['Asia/Ho_Chi_Minh', false],
        'general.currency' => ['VND', false],
        'general.company_name' => [null, false],
        'general.company_tax_code' => [null, false],
        'general.company_address' => [null, false],
        'general.contact_phone' => [null, false],
        'general.contact_email' => [null, false],
        // Email (SMTP) — overrides MAIL_* from .env when mail.mailer is set
        'mail.mailer' => [null, false],
        'mail.host' => [null, false],
        'mail.port' => [null, false],
        'mail.username' => [null, false],
        'mail.password' => [null, true],
        'mail.encryption' => [null, false],
        'mail.from_address' => [null, false],
        'mail.from_name' => [null, false],
        'mail.last_test' => [null, false],
        // Google OAuth (Search Console / GA4) — overrides GOOGLE_* from .env when set
        'google.client_id' => [null, false],
        'google.client_secret' => [null, true],
        'google.ga4_enabled' => [false, false],
        'google.last_test' => [null, false],
        // Alerting
        'alerts.emails' => [[], false],
        'alerts.telegram_bot_token' => [null, true],
        'alerts.telegram_chat_id' => [null, false],
        'alerts.cooldown_minutes' => [null, false],
        'alerts.last_test' => [null, false],
        // Backup
        'backup.schedule_enabled' => [false, false],
        'backup.schedule_time' => ['02:00', false],
        // Lifecycle (written by installer / upgrade / scheduler — not editable via API)
        'system.installed_at' => [null, false],
        'system.installation_id' => [null, false],
        'system.version' => [null, false],
        'system.heartbeat_at' => [null, false],
        'system.ssl' => [null, false],
        'monitor.state' => [[], false],
        // Setup wizard
        'setup.completed_at' => [null, false],
        'setup.acknowledged' => [[], false],
    ];

    /** @var array<string, array{value: ?string, secret: bool}>|null */
    private ?array $rows = null;

    public static function available(): bool
    {
        try {
            return Schema::hasTable('system_settings');
        } catch (Throwable) {
            return false;
        }
    }

    public function get(string $key): mixed
    {
        $def = $this->definition($key);
        if ($def[1]) {
            throw new InvalidArgumentException("{$key} is a secret; use secret().");
        }
        $row = $this->rows()[$key] ?? null;
        if ($row === null || $row['value'] === null) {
            return $def[0];
        }

        return json_decode($row['value'], true);
    }

    public function string(string $key): ?string
    {
        $v = $this->get($key);

        return is_scalar($v) && (string) $v !== '' ? (string) $v : null;
    }

    public function secret(string $key): ?string
    {
        $def = $this->definition($key);
        if (! $def[1]) {
            throw new InvalidArgumentException("{$key} is not a secret.");
        }
        $row = $this->rows()[$key] ?? null;
        if ($row === null || $row['value'] === null) {
            return null;
        }
        try {
            return Crypt::decryptString((string) json_decode($row['value'], true));
        } catch (Throwable) {
            return null; // APP_KEY changed: treat as not configured, never crash.
        }
    }

    public function hasSecret(string $key): bool
    {
        return $this->secret($key) !== null;
    }

    public function set(string $key, mixed $value, ?string $userId = null): void
    {
        $def = $this->definition($key);
        $stored = null;
        if ($value !== null && $value !== '') {
            // The column is JSON: secrets are stored as a JSON string holding the Crypt payload.
            $stored = (string) json_encode($def[1] ? Crypt::encryptString((string) $value) : $value);
        }

        $model = SystemSetting::query()->find($key) ?? new SystemSetting;
        $model->forceFill(['key' => $key, 'value' => $stored, 'is_secret' => $def[1], 'updated_by' => $userId])->save();
        $this->flush();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, ?string $userId = null): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $userId);
        }
    }

    /**
     * Values under a prefix for the API: secrets become `{key}_configured: bool`.
     *
     * @return array<string, mixed>
     */
    public function publicGroup(string $prefix): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $key => [$default, $secret]) {
            if (! str_starts_with($key, $prefix.'.')) {
                continue;
            }
            $short = substr($key, strlen($prefix) + 1);
            if ($secret) {
                $out[$short.'_configured'] = $this->hasSecret($key);
            } else {
                $out[$short] = $this->get($key);
            }
        }

        return $out;
    }

    public function flush(): void
    {
        $this->rows = null;
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // cache unavailable: nothing to invalidate
        }
    }

    /**
     * @return array{0: mixed, 1: bool}
     */
    private function definition(string $key): array
    {
        return self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown system setting {$key}.");
    }

    /**
     * @return array<string, array{value: ?string, secret: bool}>
     */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }
        if (! self::available()) {
            return []; // not memoised: the table may be created later in this process (installer, tests)
        }

        $load = static fn (): array => SystemSetting::query()->get()
            ->mapWithKeys(fn (SystemSetting $s) => [$s->key => ['value' => $s->value, 'secret' => $s->is_secret]])
            ->all();

        try {
            /** @var array<string, array{value: ?string, secret: bool}> $rows */
            $rows = Cache::remember(self::CACHE_KEY, 300, $load);
        } catch (Throwable) {
            $rows = $load();
        }

        return $this->rows = $rows;
    }
}
