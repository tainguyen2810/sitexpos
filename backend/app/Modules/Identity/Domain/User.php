<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Domain;

use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Identity\Enums\UserStatus;
use SiteXPost\Modules\Platform\Enums\PlatformPermission;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property PlatformRole|null $platform_role
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string $locale
 * @property string $timezone
 * @property string|null $last_workspace_id
 * @property UserStatus $status
 * @property Carbon|null $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable, TwoFactorAuthenticatable;

    /**
     * platform_role and status are intentionally NOT fillable.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password', 'locale', 'timezone'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'locale' => 'vi',
        'timezone' => 'Asia/Ho_Chi_Minh',
        'status' => 'active',
        'email_verified_at' => null,
        'platform_role' => null,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'last_workspace_id' => null,
        'last_login_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'platform_role' => PlatformRole::class,
            'status' => UserStatus::class,
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim($value));
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isPlatformStaff(): bool
    {
        return $this->platform_role !== null;
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function hasPlatformPermission(PlatformPermission $permission): bool
    {
        return $this->platform_role?->allows($permission) ?? false;
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPassword($token));
    }
}
