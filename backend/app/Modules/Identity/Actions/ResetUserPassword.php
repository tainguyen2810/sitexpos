<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Actions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\SessionManager;

final class ResetUserPassword implements ResetsUserPasswords
{
    public function __construct(private readonly SessionManager $sessions) {}

    /**
     * @param  User  $user
     * @param  array<string, mixed>  $input
     */
    public function reset($user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ])->validate();

        $user->forceFill(['password' => $input['password']])->save();

        // Invalidate every existing session after a reset.
        $this->sessions->revokeAll($user);
        Audit::record('auth.password_reset', $user, actorId: $user->id);
    }
}
