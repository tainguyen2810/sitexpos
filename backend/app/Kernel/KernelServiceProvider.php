<?php

declare(strict_types=1);

namespace SiteXPost\Kernel;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use SiteXPost\Kernel\Security\SsrfGuard;
use SiteXPost\Kernel\Tenancy\TenantContext;

final class KernelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: reset per request and between queued jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->bind(SsrfGuard::class, static fn (): SsrfGuard => SsrfGuard::fromConfig());
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships(false);
        JsonResource::withoutWrapping();
        JsonResource::wrap('data');

        // The breached-password check calls an external API; skipped in tests only.
        Password::defaults(fn () => $this->app->runningUnitTests()
            ? Password::min(10)->letters()->numbers()
            : Password::min(10)->letters()->numbers()->uncompromised());

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        $limits = (array) config('sitex.rate_limits');

        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute((int) ($limits['api_per_user'] ?? 120))
            ->by('user:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('workspace', static function (Request $request) use ($limits): Limit {
            $param = $request->route('workspace');
            $key = $param instanceof Model ? (string) $param->getKey() : (string) $param;

            return Limit::perMinute((int) ($limits['api_per_workspace'] ?? 600))->by('ws:'.$key);
        });

        RateLimiter::for('login', static fn (Request $request): array => [
            Limit::perMinute((int) ($limits['login_per_minute'] ?? 5))
                ->by('login:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perHour((int) ($limits['login_per_hour_ip'] ?? 60))->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('two-factor', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by('2fa:'.($request->hasSession() ? (string) $request->session()->get('login.id') : $request->ip())));

        RateLimiter::for('auth', static fn (Request $request): Limit => Limit::perMinute((int) ($limits['auth_per_minute_ip'] ?? 30))
            ->by('auth:'.$request->ip()));

        RateLimiter::for('invitation-public', static fn (Request $request): Limit => Limit::perMinute(20)
            ->by('inv:'.$request->ip()));
    }
}
