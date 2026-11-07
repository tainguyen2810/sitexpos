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
    // WordPress plugin source served by GET /api/v1/plugin/sitex-post.zip, with the
    // default Cloud URL rewritten to connector.public_url.
    'plugin' => [
        'source_path' => env('SITEX_PLUGIN_SOURCE_PATH', base_path('../wordpress-plugin/sitex-post')),
        'downloads_per_minute_ip' => (int) env('SITEX_PLUGIN_DOWNLOADS_PER_MINUTE_IP', 20),
    ],

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

    // Phase 6 — Media Engine (MEDIA-ENGINE.md)
    'media' => [
        'disk' => env('SITEX_MEDIA_DISK', 'local'),
        'max_upload_bytes' => (int) env('SITEX_MEDIA_MAX_UPLOAD_BYTES', 10 * 1024 * 1024),
        'max_dimension' => (int) env('SITEX_MEDIA_MAX_DIMENSION', 8000),
        'max_pixels' => (int) env('SITEX_MEDIA_MAX_PIXELS', 40_000_000),
        'max_storage_bytes' => (int) env('SITEX_MEDIA_MAX_STORAGE_BYTES', 2 * 1024 * 1024 * 1024),
        'max_uploads_per_day' => (int) env('SITEX_MEDIA_MAX_UPLOADS_PER_DAY', 200),
        'max_ai_images_per_day' => (int) env('SITEX_MEDIA_MAX_AI_IMAGES_PER_DAY', 30),
        'max_ai_cost_per_job_micro_usd' => (int) env('SITEX_MEDIA_MAX_AI_COST_PER_JOB', 500_000),
        'max_inline_images' => (int) env('SITEX_MEDIA_MAX_INLINE_IMAGES', 8),
        // Auto images after a draft (STYLE-AND-AUTO-IMAGES.md §2): library first, AI for the rest.
        // A writing preset can override both (defaults.auto_images / defaults.min_images).
        'auto_images' => (bool) env('SITEX_MEDIA_AUTO_IMAGES', true),
        'min_article_images' => (int) env('SITEX_MEDIA_MIN_ARTICLE_IMAGES', 2),
        'auto_image_style' => env('SITEX_MEDIA_AUTO_IMAGE_STYLE', 'editorial_concept'),
        'font_dir' => env('SITEX_MEDIA_FONT_DIR', resource_path('fonts')),
    ],

    // Phase 6 — Publishing (WORDPRESS-PUBLISHER.md)
    'publishing' => [
        'max_publishes_per_day' => (int) env('SITEX_PUBLISH_MAX_PER_DAY', 50),
        'max_bulk_items' => (int) env('SITEX_PUBLISH_MAX_BULK_ITEMS', 50),
        'max_schedule_days_ahead' => (int) env('SITEX_PUBLISH_MAX_SCHEDULE_DAYS', 180),
        'backoff_minutes' => [1, 5, 15, 60, 180],
        'lock_seconds' => 300,
    ],

    // Phase 8 — SEO Analytics (ANALYTICS-ARCHITECTURE.md, GSC-INTEGRATION.md)
    'analytics' => [
        'backfill_days' => (int) env('SITEX_ANALYTICS_BACKFILL_DAYS', 90),
        'max_backfill_days' => 480, // Search Console keeps ~16 months
        'lag_days' => 3,            // GSC data is final after 2–3 days
        'max_days_per_run' => 31,
        'page_size' => 25000,       // GSC rowLimit maximum
        'max_pages_per_request' => 10,
        'window_days' => 28,
        'search_types' => ['web'],
        'copilot_ai' => (bool) env('SITEX_COPILOT_AI', true),
    ],

    // Phase 8 — Autonomous SEO Agent (AUTONOMOUS-SEO-AGENT.md)
    'agent' => [
        'default_autonomy_level' => 1,
        'max_tasks_per_run' => (int) env('SITEX_AGENT_MAX_TASKS_PER_RUN', 10),
        'task_timeout_seconds' => 900,
        'run_timeout_minutes' => 120,
        'memory_retention_days' => 180,
        'log_retention_days' => 365,
    ],

    // Phase 9 — release version of this code (compared with the version recorded in the
    // database to detect a pending upgrade). Bump together with CHANGELOG.md.
    'version' => '1.0.8',

    // Phase 9 — web installer (INSTALLATION-WIZARD.md, INSTALLER-SECURITY.md)
    'installer' => [
        // Turn the /install wizard off entirely (e.g. servers set up via CLI).
        'enabled' => (bool) env('SITEX_INSTALLER_ENABLED', true),
        // Written by the installer as its last step. One of several "installed" signals.
        'installed' => (bool) env('SITEX_INSTALLED', false),
        // Private directory for the setup token, progress state and lock (never web-served).
        'state_path' => env('SITEX_INSTALLER_STATE_PATH', storage_path('app/installer')),
        'token_ttl_hours' => (int) env('SITEX_INSTALLER_TOKEN_TTL_HOURS', 24),
        // The installer must not depend on the database or Redis for rate limiting.
        'cache_store' => env('SITEX_INSTALLER_CACHE_STORE', 'file'),
        // .env written by the installer (override only for tests).
        'env_path' => env('SITEX_INSTALLER_ENV_PATH'),
        'min_php' => '8.3.0',
        'required_extensions' => ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'bcmath', 'fileinfo', 'curl', 'gd', 'intl'],
        'min_free_disk_mb' => 1024,
        // Pre-filled form values (the Docker image sets these; docs/DOCKER.md).
        // Only hints for the operator: every value is still tested and confirmed.
        'defaults' => [
            'db_host' => env('SITEX_INSTALLER_DB_HOST'),
            'db_port' => env('SITEX_INSTALLER_DB_PORT'),
            'db_database' => env('SITEX_INSTALLER_DB_DATABASE'),
            'db_username' => env('SITEX_INSTALLER_DB_USERNAME'),
            'redis_host' => env('SITEX_INSTALLER_REDIS_HOST'),
            'redis_port' => env('SITEX_INSTALLER_REDIS_PORT'),
            'app_url' => env('SITEX_PUBLIC_API_URL'),
            'frontend_url' => env('SITEX_PUBLIC_APP_URL'),
            'admin_url' => env('SITEX_PUBLIC_ADMIN_URL'),
        ],
    ],

    // How this installation is run: 'server' (aaPanel/Nginx/Supervisor, DEPLOYMENT.md)
    // or 'docker' (docker compose, DOCKER.md). Only changes operator hints.
    'deployment' => env('SITEX_DEPLOYMENT', 'server') === 'docker' ? 'docker' : 'server',

    // Phase 9 — operations (SYSTEM HEALTH, BACKUP-RESTORE.md, MONITORING-RUNBOOK.md)
    'system' => [
        // Scheduler heartbeat older than this = scheduler not running.
        'heartbeat_stale_seconds' => 180,
        'queue_backlog_warning' => (int) env('SITEX_QUEUE_BACKLOG_WARNING', 500),
        'failed_jobs_warning' => (int) env('SITEX_FAILED_JOBS_WARNING', 10),
        'disk_warning_percent' => (int) env('SITEX_DISK_WARNING_PERCENT', 85),
        'disk_critical_percent' => (int) env('SITEX_DISK_CRITICAL_PERCENT', 95),
        'ssl_warning_days' => (int) env('SITEX_SSL_WARNING_DAYS', 14),
        'backup_max_age_hours' => (int) env('SITEX_BACKUP_MAX_AGE_HOURS', 26),
        'alert_cooldown_minutes' => (int) env('SITEX_ALERT_COOLDOWN_MINUTES', 60),
        'backup' => [
            // Local staging directory. Must be outside the web root; copy offsite with `disk`.
            'path' => env('SITEX_BACKUP_PATH', storage_path('app/backups')),
            // Filesystem disk (config/filesystems.php) that receives an offsite copy, e.g. "s3" or "sftp".
            'disk' => env('SITEX_BACKUP_DISK'),
            // Separate from APP_KEY: base64 32-byte key. Store a copy OUTSIDE the server.
            'encryption_key' => env('SITEX_BACKUP_ENCRYPTION_KEY'),
            'retention_days' => (int) env('SITEX_BACKUP_RETENTION_DAYS', 14),
            'mysqldump' => env('SITEX_MYSQLDUMP_PATH', 'mysqldump'),
            'mysql' => env('SITEX_MYSQL_PATH', 'mysql'),
            'timeout_seconds' => (int) env('SITEX_BACKUP_TIMEOUT', 3600),
        ],
        'plugin_zip_dir' => env('SITEX_PLUGIN_ZIP_DIR', base_path('../wordpress-plugin/dist')),
    ],
];
