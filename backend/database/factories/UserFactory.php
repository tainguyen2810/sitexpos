<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Identity\Enums\UserStatus;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password12345'),
            'remember_token' => Str::random(10),
            'locale' => 'vi',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => UserStatus::Active,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }

    public function platform(PlatformRole $role, bool $withTwoFactor = true): static
    {
        return $this->state(fn () => [
            'platform_role' => $role,
            'two_factor_secret' => $withTwoFactor ? encrypt('JBSWY3DPEHPK3PXP') : null,
            'two_factor_confirmed_at' => $withTwoFactor ? now() : null,
        ]);
    }
}
