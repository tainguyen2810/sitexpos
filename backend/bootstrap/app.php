<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use SiteXPost\Kernel\Http\ApiExceptionRenderer;
use SiteXPost\Kernel\Http\Middleware\AssignRequestId;
use SiteXPost\Kernel\Http\Middleware\SecurityHeaders;
use SiteXPost\Kernel\Http\Middleware\SetLocale;
use SiteXPost\Kernel\Tenancy\Middleware\ResolveWorkspace;
use SiteXPost\Modules\Identity\Http\Middleware\EnsureEmailIsVerified;
use SiteXPost\Modules\Identity\Http\Middleware\EnsureUserIsActive;
use SiteXPost\Modules\Platform\Http\Middleware\EnsurePlatformStaff;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->prepend(AssignRequestId::class);
        // Global: Accept-Language for every response (incl. auth errors); re-run in the api
        // group after authentication to apply the user's saved preference.
        $middleware->append(SetLocale::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->api(append: [SetLocale::class]);

        $middleware->alias([
            'sitex.tenant' => ResolveWorkspace::class,
            'sitex.active' => EnsureUserIsActive::class,
            'sitex.verified' => EnsureEmailIsVerified::class,
            'sitex.platform' => EnsurePlatformStaff::class,
        ]);

        // The tenant must be resolved BEFORE route-model binding so that child
        // models ({member}, {invitation}...) are loaded inside the tenant scope.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveWorkspace::class);

        // Pure JSON API: never redirect guests to a login page.
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        // Behind a reverse proxy (Docker image: aaPanel Nginx → container), trust the
        // proxy's X-Forwarded-* headers so client IPs (rate limits, audit) and HTTPS are
        // seen correctly. Real environment variable only (docs/DOCKER.md); unset = trust none.
        $proxies = getenv('SITEX_TRUSTED_PROXIES');
        if (is_string($proxies) && trim($proxies) !== '') {
            $middleware->trustProxies(at: trim($proxies) === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ApiExceptionRenderer::shouldRender($request),
        );
        $exceptions->render(fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e, $request));
    })->create();

// Docker image: the .env written by the installer lives on a persistent volume
// instead of inside the (replaceable) image. Real environment variable only.
$envDir = getenv('SITEX_ENV_DIR');
if (is_string($envDir) && $envDir !== '') {
    $app->useEnvironmentPath($envDir);
}

return $app;
