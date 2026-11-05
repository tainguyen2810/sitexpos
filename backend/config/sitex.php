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

    // Phase 2 — WordPress connector (SECURITY.md §6)
    'connector' => [
        // Public base URL of this API as seen by WordPress sites (no trailing slash).
        'public_url' => rtrim((string) env('SITEX_CONNECTOR_PUBLIC_URL', env('APP_URL', 'http://localhost:8000')), '/'),
        'clock_skew' => (int) env('SITEX_CONNECTOR_CLOCK_SKEW', 300),
        'nonce_ttl' => (int) env('SITEX_CONNECTOR_NONCE_TTL', 600),
        'pairing_ttl_minutes' => (int) env('SITEX_PAIRING_TTL_MINUTES', 15),
        'pairing_max_attempts' => (int) env('SITEX_PAIRING_MAX_ATTEMPTS', 5),
        'pairing_per_hour_ip' => (int) env('SITEX_PAIRING_PER_HOUR_IP', 10),
        'requests_per_minute_site' => (int) env('SITEX_CONNECTOR_PER_MINUTE_SITE', 60),
        'rotation_grace_minutes' => (int) env('SITEX_KEY_ROTATION_GRACE_MINUTES', 10),
        'connect_timeout' => (int) env('SITEX_CONNECTOR_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('SITEX_CONNECTOR_TIMEOUT', 20),
        'max_response_bytes' => 5 * 1024 * 1024,
        'media_max_bytes' => (int) env('SITEX_MEDIA_MAX_BYTES', 10 * 1024 * 1024),
        'health_interval_minutes' => (int) env('SITEX_HEALTH_INTERVAL_MINUTES', 30),
        'sync_max_pages' => (int) env('SITEX_SYNC_MAX_PAGES', 50),
        'min_plugin_version' => '1.0.0',
        'log_retention_days' => 30,
    ],

    'ssrf' => [
        // Development only. Ignored when APP_ENV=production.
        'allow_hosts' => array_filter(explode(',', (string) env('SITEX_SSRF_ALLOW_HOSTS', ''))),
        'allow_http' => (bool) env('SITEX_SSRF_ALLOW_HTTP', false),
    ],
];
