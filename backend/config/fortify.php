<?php

use Laravel\Fortify\Features;
use SiteXPost\Kernel\Http\Middleware\SetLocale;

/*
 * Fortify runs headless (no views): the Vue SPA calls these JSON endpoints.
 * All routes live under /api/v1/auth so that errors use the API envelope.
 */
return [
    'guard' => 'web',
    'middleware' => ['web', 'throttle:auth', SetLocale::class],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'views' => false,
    'home' => env('FRONTEND_URL', 'http://localhost:5173'),
    'prefix' => 'api/v1/auth',
    'domain' => null,
    'lowercase_usernames' => true,
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        'passkeys' => null,
    ],
    'paths' => [],
    'redirects' => [
        'login' => null,
        'logout' => null,
        'password-confirmation' => null,
        'register' => null,
        'email-verification' => null,
        'password-reset' => null,
    ],
    'passkeys' => [
        'relying_party_id' => parse_url((string) config('app.url'), PHP_URL_HOST),
        'allowed_origins' => [config('app.url')],
        'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),
        'timeout' => 60000,
    ],
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
];
