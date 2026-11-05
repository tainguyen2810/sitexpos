<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Kernel\Modules\ModuleServiceProvider;
use SiteXPost\Modules\Identity\Actions\CreateNewUser;
use SiteXPost\Modules\Identity\Actions\ResetUserPassword;
use SiteXPost\Modules\Identity\Actions\UpdateUserPassword;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Http\Responses\GenericPasswordResetLinkResponse;
use SiteXPost\Modules\Identity\Listeners\RecordAuthEvents;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SessionManager::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, fn ($app, array $params) => new GenericPasswordResetLinkResponse((string) ($params['status'] ?? '')));
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, fn ($app, array $params) => new GenericPasswordResetLinkResponse((string) ($params['status'] ?? '')));
    }

    protected function bootModule(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::authenticateUsing(static function (Request $request): ?User {
            $user = User::query()->where('email', mb_strtolower((string) $request->input('email')))->first();

            if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            // Only disclose suspension once the password has been proven.
            if (! $user->isActive()) {
                throw ApiException::forbidden('account_suspended', __('api.account_suspended'));
            }

            return $user;
        });

        // Links in emails point to the SPA, which then calls the signed API URL (same host as APP_URL).
        VerifyEmail::createUrlUsing(static function (User $user): string {
            $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]);

            return rtrim((string) config('sitex.frontend_url'), '/').'/verify-email?'.http_build_query(['link' => $signed]);
        });

        ResetPassword::createUrlUsing(static fn (User $user, string $token): string => rtrim((string) config('sitex.frontend_url'), '/')
            .'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]));

        Event::subscribe(RecordAuthEvents::class);
    }
}
