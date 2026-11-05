<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'admin_url' => env('ADMIN_URL', 'http://localhost:5174'),

    'invitations' => [
        'expire_days' => (int) env('SITEX_INVITATION_EXPIRE_DAYS', 7),
    ],

    'impersonation' => [
        'max_minutes' => (int) env('SITEX_IMPERSONATION_MAX_MINUTES', 30),
    ],

    'rate_limits' => [
        'api_per_user' => (int) env('SITEX_RATE_API_PER_USER', 120),
        'api_per_workspace' => (int) env('SITEX_RATE_API_PER_WORKSPACE', 600),
        'login_per_minute' => (int) env('SITEX_RATE_LOGIN_PER_MINUTE', 5),
        'login_per_hour_ip' => (int) env('SITEX_RATE_LOGIN_PER_HOUR_IP', 60),
        'auth_per_minute_ip' => (int) env('SITEX_RATE_AUTH_PER_MINUTE_IP', 30),
    ],

    'ssrf' => [
        // Development only. Ignored when APP_ENV=production.
        'allow_hosts' => array_filter(explode(',', (string) env('SITEX_SSRF_ALLOW_HOSTS', ''))),
        'allow_http' => (bool) env('SITEX_SSRF_ALLOW_HTTP', false),
    ],
];
