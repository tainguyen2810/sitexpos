<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Actions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use SiteXPost\Kernel\Http\Middleware\SetLocale;
use SiteXPost\Modules\Identity\Domain\User;

final class CreateNewUser implements CreatesNewUsers
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'locale' => ['nullable', Rule::in(SetLocale::SUPPORTED)],
            'timezone' => ['nullable', 'timezone:all'],
            'platform_role' => ['prohibited'],
        ])->validate();

        return User::query()->create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'locale' => $input['locale'] ?? app()->getLocale(),
            'timezone' => $input['timezone'] ?? 'Asia/Ho_Chi_Minh',
        ]);
    }
}
