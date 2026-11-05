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

return Application::configure(basePath: dirname(__DIR__))
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ApiExceptionRenderer::shouldRender($request),
        );
        $exceptions->render(fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e, $request));
    })->create();
