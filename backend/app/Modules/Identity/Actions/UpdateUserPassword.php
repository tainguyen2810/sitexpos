<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Actions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\SessionManager;

final class UpdateUserPassword implements UpdatesUserPasswords
{
    public function __construct(private readonly SessionManager $sessions) {}

    /**
     * @param  User  $user
     * @param  array<string, mixed>  $input
     */
    public function update($user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed', 'different:current_password'],
        ])->validateWithBag('updatePassword');

        $user->forceFill(['password' => $input['password']])->save();

        $this->sessions->revokeOthers($user);
        Audit::record('auth.password_changed', $user);
    }
}
