<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Domain;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Payment provider configuration; credentials are encrypted and never exposed.
 *
 * @property string $id
 * @property string $code
 * @property string $adapter
 * @property string $name
 * @property bool $is_enabled
 * @property array<string, mixed>|null $settings
 * @property ?string $credentials
 * @property int $display_order
 * @property ?string $updated_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class PaymentProviderConfig extends Model
{
    use HasUlids;

    protected $table = 'payment_provider_configs';

    protected $guarded = ['*'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'array',
            'display_order' => 'integer',
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    public function setCredentials(array $values): void
    {
        $current = $this->credentialValues();
        foreach ($values as $k => $v) {
            if ($v === '') {
                unset($current[$k]);
            } else {
                $current[$k] = $v;
            }
        }
        $this->setAttribute('credentials', $current === [] ? null : Crypt::encryptString((string) json_encode($current)));
    }

    public function secret(string $key): ?string
    {
        $v = $this->credentialValues()[$key] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Names of the credentials that are set (never their values).
     *
     * @return list<string>
     */
    public function credentialKeys(): array
    {
        return array_keys($this->credentialValues());
    }

    /**
     * @return array<string, string>
     */
    private function credentialValues(): array
    {
        if ($this->credentials === null || $this->credentials === '') {
            return [];
        }
        try {
            $decoded = json_decode(Crypt::decryptString($this->credentials), true);
        } catch (DecryptException) {
            return [];
        }

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return is_array($this->settings) && array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }
}
