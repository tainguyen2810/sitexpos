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
        'ai_tasks_per_minute' => (int) env('SITEX_RATE_AI_TASKS_PER_MINUTE', 20),
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

    // Phase 3 — AI Gateway & Queue (ARCHITECTURE.md §8, §10). Keys here (.env) are the
    // fallback: a key a Super Admin saves in the UI is stored encrypted (Crypt) and wins.
    // Built-in base URLs come from here; custom providers' base URLs are SSRF-checked.
    'ai' => [
        'providers' => [
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com'), '/'),
            ],
            'gemini' => [
                'api_key' => env('GEMINI_API_KEY'),
                'base_url' => rtrim((string) env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'), '/'),
            ],
            'anthropic' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
                'base_url' => rtrim((string) env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/'),
                // Raw-HTTP API version header (verified 05/11/2026: 2026-10-21 rejects temperature/top_p/top_k).
                'version' => env('ANTHROPIC_API_VERSION', '2026-10-21'),
            ],
            'deepseek' => [
                'api_key' => env('DEEPSEEK_API_KEY'),
                // OpenAI-format base (verified 05/11/2026 on api-docs.deepseek.com).
                'base_url' => rtrim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'), '/'),
            ],
        ],
        // Deterministic offline provider for development/tests. Always disabled in production.
        'fake_provider' => (bool) env('SITEX_AI_FAKE_PROVIDER', env('APP_ENV', 'production') !== 'production'),
        'connect_timeout' => (int) env('SITEX_AI_CONNECT_TIMEOUT', 5),
        'max_backoff_seconds' => (int) env('SITEX_AI_MAX_BACKOFF_SECONDS', 8),
        // Total time one gateway call may spend on retries/fallbacks (< job timeout 300 s).
        'call_deadline_seconds' => (int) env('SITEX_AI_CALL_DEADLINE_SECONDS', 240),
        // Estimated characters per token used for pre-call cost reservations (conservative).
        'chars_per_token' => (int) env('SITEX_AI_CHARS_PER_TOKEN', 3),
        'reservation_ttl_minutes' => (int) env('SITEX_AI_RESERVATION_TTL_MINUTES', 30),
        'max_prompt_chars' => (int) env('SITEX_AI_MAX_PROMPT_CHARS', 20000),
        'request_log_retention_days' => (int) env('SITEX_AI_REQUEST_LOG_RETENTION_DAYS', 90),
    ],

    'workflows' => [
        // Max workflow runs executing at the same time for one workspace (fairness).
        'tenant_concurrency' => (int) env('SITEX_WORKFLOW_TENANT_CONCURRENCY', 2),
        // Seconds a worker owns a run before recovery may take it over (> job timeout).
        'lease_seconds' => (int) env('SITEX_WORKFLOW_LEASE_SECONDS', 360),
        'backoff_base_seconds' => (int) env('SITEX_WORKFLOW_BACKOFF_BASE', 15),
        'backoff_max_seconds' => (int) env('SITEX_WORKFLOW_BACKOFF_MAX', 900),
        // Delay before re-checking a run deferred by the tenant concurrency limit.
        'defer_seconds' => (int) env('SITEX_WORKFLOW_DEFER_SECONDS', 10),
        // A pending run with no worker activity for this long is re-dispatched by recovery.
        'stale_pending_seconds' => (int) env('SITEX_WORKFLOW_STALE_PENDING_SECONDS', 600),
        'retention_days' => (int) env('SITEX_WORKFLOW_RETENTION_DAYS', 90),
    ],

    'ssrf' => [
        // Development only. Ignored when APP_ENV=production.
        'allow_hosts' => array_filter(explode(',', (string) env('SITEX_SSRF_ALLOW_HOSTS', ''))),
        'allow_http' => (bool) env('SITEX_SSRF_ALLOW_HTTP', false),
    ],
];
