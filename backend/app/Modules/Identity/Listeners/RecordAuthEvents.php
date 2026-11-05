<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Identity\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;

final class RecordAuthEvents
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Registered::class, function (Registered $e): void {
            $this->audit('auth.registered', $e->user);
        });

        $events->listen(Login::class, function (Login $e): void {
            if ($e->user instanceof User) {
                $e->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
            $this->audit('auth.login', $e->user);
        });

        $events->listen(Logout::class, function (Logout $e): void {
            $this->audit('auth.logout', $e->user);
        });

        $events->listen(Failed::class, function (Failed $e): void {
            // Never store the attempted password; email is hashed to avoid building a list of probes.
            $email = is_string($e->credentials['email'] ?? null) ? $e->credentials['email'] : '';
            Audit::record('auth.login_failed', null, ['email_hash' => hash('sha256', mb_strtolower($email))], actorId: $e->user?->getAuthIdentifier() !== null ? (string) $e->user->getAuthIdentifier() : null, actorType: 'system');
        });

        $events->listen(Verified::class, function (Verified $e): void {
            $this->audit('auth.email_verified', $e->user);
        });

        $events->listen(TwoFactorAuthenticationEnabled::class, fn (TwoFactorAuthenticationEnabled $e) => $this->audit('auth.2fa_enabled', $e->user));
        $events->listen(TwoFactorAuthenticationConfirmed::class, fn (TwoFactorAuthenticationConfirmed $e) => $this->audit('auth.2fa_confirmed', $e->user));
        $events->listen(TwoFactorAuthenticationDisabled::class, fn (TwoFactorAuthenticationDisabled $e) => $this->audit('auth.2fa_disabled', $e->user));
        $events->listen(RecoveryCodesGenerated::class, fn (RecoveryCodesGenerated $e) => $this->audit('auth.2fa_recovery_codes_regenerated', $e->user));
    }

    private function audit(string $action, mixed $user): void
    {
        if ($user instanceof User) {
            Audit::record($action, $user, actorId: $user->id);
        }
    }
}
