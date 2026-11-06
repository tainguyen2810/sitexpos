<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Actions;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use SiteXPost\Kernel\Logging\SecretRedactor;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Identity\Domain\User;
use SiteXPost\Modules\Identity\Enums\PlatformRole;
use SiteXPost\Modules\Installer\Support\DatabaseProbe;
use SiteXPost\Modules\Installer\Support\DatabaseTarget;
use SiteXPost\Modules\Installer\Support\EnvWriter;
use SiteXPost\Modules\Installer\Support\InstallationState;
use SiteXPost\Modules\Installer\Support\InstallerStorage;
use SiteXPost\Modules\Installer\Support\PendingConfig;
use SiteXPost\Modules\Installer\Support\SetupToken;
use SiteXPost\Modules\System\Support\MigrationStatus;
use SiteXPost\Modules\System\Support\SystemSettings;
use Throwable;

/**
 * Final installer step (INSTALLATION-WIZARD.md §Step 7). Every sub-step is
 * idempotent and recorded in progress.json so a failed run can be retried or
 * resumed without touching data it did not create:
 *  revalidate → verify database target → write .env → migrate (allowlisted:
 *  only `migrate --force`) → seed system defaults → create Super Admin →
 *  verify integrity → mark installed (lock file + .env flag + DB marker) →
 *  revoke the setup token.
 */
final class RunInstallation
{
    public const STEPS = ['revalidate', 'verify_database', 'write_env', 'migrate', 'seed', 'create_admin', 'verify', 'mark_installed'];

    private const RUN_LOCK = 'run.lock';

    public function __construct(
        private readonly InstallerStorage $storage,
        private readonly InstallationState $state,
        private readonly PendingConfig $pending,
        private readonly DatabaseTarget $target,
        private readonly EnvWriter $env,
        private readonly SetupToken $token,
    ) {}

    /**
     * @return array{status: string, steps: array<string, string>, failed_step: ?string, error: ?string, error_code: ?string}
     */
    public function handle(): array
    {
        $lock = $this->storage->lock(self::RUN_LOCK);
        if ($lock === null) {
            throw new RuntimeException('installation_in_progress');
        }

        @set_time_limit(600);
        $progress = $this->state->progress();
        $run = is_array($progress['run'] ?? null) ? $progress['run'] : [];
        $run['status'] = 'running';
        $run['attempts'] = (int) ($run['attempts'] ?? 0) + 1;
        $run['started_at'] = now()->toIso8601ZuluString();
        $run['failed_step'] = null;
        $run['error'] = null;
        $run['error_code'] = null;
        $steps = is_array($run['steps'] ?? null) ? $run['steps'] : [];
        $this->saveRun($run + ['steps' => $steps]);

        $current = 'revalidate';
        try {
            foreach (self::STEPS as $step) {
                $current = $step;
                $steps[$step] = 'running';
                $run['steps'] = $steps;
                $this->saveRun($run);
                $this->{'step'.Str::studly($step)}($run);
                $steps[$step] = 'done';
                $run['steps'] = $steps;
                $this->saveRun($run);
            }
            $run['status'] = 'completed';
            $run['finished_at'] = now()->toIso8601ZuluString();
            $this->saveRun($run);
            $this->pending->clear();
            $this->token->revoke();
        } catch (Throwable $e) {
            $steps[$current] = 'failed';
            $code = $e instanceof RuntimeException && preg_match('/^[a-z_]+$/', $e->getMessage()) === 1 ? $e->getMessage() : 'step_failed';
            $run['steps'] = $steps;
            $run['status'] = 'failed';
            $run['failed_step'] = $current;
            $run['error_code'] = $code;
            $run['error'] = $this->safeMessage($e);
            $this->saveRun($run);
            $this->log('failed '.$current.': '.$run['error']);
        } finally {
            $this->storage->unlock($lock);
        }

        return [
            'status' => (string) $run['status'],
            'steps' => $steps,
            'failed_step' => $run['failed_step'] ?? null,
            'error' => $run['error'] ?? null,
            'error_code' => $run['error_code'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function stepRevalidate(array &$run): void
    {
        foreach (['database', 'application', 'queue', 'admin'] as $section) {
            if (! $this->pending->has($section)) {
                throw new RuntimeException('missing_'.$section);
            }
        }
        if ($this->state->isInstalled()) {
            throw new RuntimeException('already_installed');
        }
        $admin = $this->pending->section('admin');
        if (! is_string($admin['password_hash'] ?? null) || $admin['password_hash'] === '') {
            throw new RuntimeException('missing_admin');
        }
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function stepVerifyDatabase(array &$run): void
    {
        $db = $this->dbConfig();
        $info = $this->target->inspect($db);
        if (! $info['connected']) {
            throw new RuntimeException('database_'.($info['error_code'] ?? 'connection_failed'));
        }
        $fingerprint = DatabaseProbe::fingerprint($db);
        $resuming = ($run['db_fingerprint'] ?? null) === $fingerprint && ($run['migrations_started'] ?? false) === true;
        if (! $info['empty'] && ! $resuming) {
            // Never install over existing data. A previous run of THIS installer
            // on the same target may be resumed (migrate only runs what is pending).
            throw new RuntimeException('database_not_empty');
        }
        $run['db_fingerprint'] = $fingerprint;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function stepWriteEnv(array &$run): void
    {
        $app = $this->pending->section('application');
        $queue = $this->pending->section('queue');
        $mail = $this->pending->section('mail');
        $db = $this->dbConfig();

        $key = $this->env->currentAppKey() ?? EnvWriter::generateKey();
        $apiUrl = rtrim((string) $app['app_url'], '/');
        $frontend = rtrim((string) $app['frontend_url'], '/');
        $admin = rtrim((string) $app['admin_url'], '/');
        $secure = str_starts_with($apiUrl, 'https://');
        $useRedis = ($queue['driver'] ?? 'redis') === 'redis';

        $values = [
            'APP_NAME' => (string) $app['app_name'],
            'APP_ENV' => 'production',
            'APP_KEY' => $key,
            'APP_DEBUG' => false,
            'APP_URL' => $apiUrl,
            'APP_LOCALE' => (string) ($app['locale'] ?? 'vi'),
            'APP_FALLBACK_LOCALE' => 'vi',
            'FRONTEND_URL' => $frontend,
            'ADMIN_URL' => $admin,
            'LOG_STACK' => 'daily',
            'LOG_LEVEL' => 'warning',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $db['host'],
            'DB_PORT' => $db['port'],
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => (string) ($db['password'] ?? ''),
            'SESSION_DRIVER' => 'database',
            'SESSION_DOMAIN' => self::sessionDomain([$apiUrl, $frontend, $admin]),
            'SESSION_SECURE_COOKIE' => $secure,
            'SESSION_SAME_SITE' => 'lax',
            'SANCTUM_STATEFUL_DOMAINS' => implode(',', array_unique(array_filter([self::hostPort($frontend), self::hostPort($admin)]))),
            'SITEX_CONNECTOR_PUBLIC_URL' => $apiUrl,
            'SITEX_DEFAULT_TIMEZONE' => (string) ($app['timezone'] ?? 'Asia/Ho_Chi_Minh'),
            'GOOGLE_REDIRECT_URI' => $apiUrl.'/api/v1/oauth/google/callback',
            'QUEUE_CONNECTION' => $useRedis ? 'redis' : 'database',
            'CACHE_STORE' => $useRedis ? 'redis' : 'database',
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_HOST' => (string) ($queue['redis_host'] ?? '127.0.0.1'),
            'REDIS_PORT' => (int) ($queue['redis_port'] ?? 6379),
            'REDIS_PASSWORD' => ($queue['redis_password'] ?? '') !== '' ? (string) $queue['redis_password'] : null,
            'SITEX_AI_FAKE_PROVIDER' => false,
            'SITEX_INSTALLER_ENABLED' => true,
        ];

        if (($mail['mailer'] ?? 'log') === 'smtp') {
            $values += [
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => (string) $mail['host'],
                'MAIL_PORT' => (int) $mail['port'],
                'MAIL_USERNAME' => ($mail['username'] ?? '') !== '' ? (string) $mail['username'] : null,
                'MAIL_PASSWORD' => ($mail['password'] ?? '') !== '' ? (string) $mail['password'] : null,
                'MAIL_SCHEME' => ($mail['encryption'] ?? '') === 'ssl' ? 'smtps' : null,
                'MAIL_FROM_ADDRESS' => (string) $mail['from_address'],
                'MAIL_FROM_NAME' => (string) ($mail['from_name'] ?? $app['app_name']),
            ];
        } else {
            $values += ['MAIL_MAILER' => 'log'];
        }

        $this->env->write($values);

        // The running process continues with the new settings; the next request reads the file.
        config(['app.key' => $this->env->currentAppKey() ?? $key, 'cache.default' => 'array']);
        app()->forgetInstance('encrypter');
        if (is_file(base_path('bootstrap/cache/config.php'))) {
            Artisan::call('config:clear'); // a stale config cache would ignore the new .env
        }
        $run['env_written_at'] = now()->toIso8601ZuluString();
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function stepMigrate(array &$run): void
    {
        $connection = $this->target->configure($this->dbConfig());
        $run['migrations_started'] = true;
        $this->saveRun($run);

        $code = Artisan::call('migrate', ['--force' => true, '--database' => $connection]);
        $this->log('migrate exit '.$code.': '.SecretRedactor::redact(Artisan::output(), 2000));
        if ($code !== 0) {
            throw new RuntimeException('migration_failed');
        }
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function stepSeed(array &$run): void
    {
        $connection = $this->target->configure($this->dbConfig());
        // Production-safe seeder only (insert-only system data). Never DemoSeeder.
        $code = Artisan::call('db:seed', ['--force' => true, '--database' => $connection, '--class' => 'Database\\Seeders\\DatabaseSeeder']);
        $this->log('seed exit '.$code);
        if ($code !== 0) {
            throw new RuntimeException('seed_failed');
        }
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function stepCreateAdmin(array &$run): void
    {
        $connection = $this->target->configure($this->dbConfig());
        $admin = $this->pending->section('admin');
        $app = $this->pending->section('application');

        $user = DB::connection($connection)->transaction(function () use ($admin, $app, $run): User {
            $existing = User::query()->where('platform_role', PlatformRole::SuperAdmin->value)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->id === ($run['admin_user_id'] ?? null)) {
                    return $existing; // resumed run: created by this installation
                }
                throw new RuntimeException('admin_exists');
            }
            $email = mb_strtolower((string) $admin['email']);
            if (User::query()->where('email', $email)->lockForUpdate()->exists()) {
                throw new RuntimeException('email_taken');
            }

            $user = new User;
            $user->forceFill([
                'name' => (string) $admin['name'],
                'email' => $email,
                'password' => (string) $admin['password_hash'], // already a bcrypt/argon hash
                'locale' => (string) ($app['locale'] ?? 'vi'),
                'timezone' => (string) ($app['timezone'] ?? 'Asia/Ho_Chi_Minh'),
                'platform_role' => PlatformRole::SuperAdmin,
                'email_verified_at' => now(),
            ])->save();

            return $user;
        });

        $run['admin_user_id'] = $user->id;
        $this->saveRun($run);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function stepVerify(array &$run): void
    {
        $this->target->configure($this->dbConfig());
        $pending = app(MigrationStatus::class)->pending();
        if ($pending !== []) {
            throw new RuntimeException('migrations_pending');
        }
        foreach (['users', 'system_settings', 'workspaces', 'migrations'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('integrity_failed');
            }
        }
        if (! User::query()->whereKey($run['admin_user_id'] ?? '')->where('platform_role', PlatformRole::SuperAdmin->value)->exists()) {
            throw new RuntimeException('integrity_failed');
        }
        if ($this->env->currentAppKey() === null) {
            throw new RuntimeException('integrity_failed');
        }
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function stepMarkInstalled(array &$run): void
    {
        $this->target->configure($this->dbConfig());
        $installationId = (string) Str::ulid();
        $settings = app(SystemSettings::class);
        $settings->flush();
        $settings->setMany([
            'system.installed_at' => now()->toIso8601ZuluString(),
            'system.installation_id' => $installationId,
            'system.version' => (string) config('sitex.version'),
            'general.brand_name' => (string) ($this->pending->section('application')['app_name'] ?? 'SiteX Post'),
            'general.default_locale' => (string) ($this->pending->section('application')['locale'] ?? 'vi'),
            'general.default_timezone' => (string) ($this->pending->section('application')['timezone'] ?? 'Asia/Ho_Chi_Minh'),
        ]);

        $admin = User::query()->find($run['admin_user_id'] ?? '');
        Audit::record('system.installed', $admin, ['version' => config('sitex.version'), 'installation_id' => $installationId], actorType: 'system');

        $this->env->write(['SITEX_INSTALLED' => true]);
        $this->state->writeLock([
            'installed_at' => now()->toIso8601ZuluString(),
            'installation_id' => $installationId,
            'version' => config('sitex.version'),
        ]);
        $run['installation_id'] = $installationId;
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: ?string}
     */
    private function dbConfig(): array
    {
        $db = $this->pending->section('database');

        return [
            'host' => (string) ($db['host'] ?? ''),
            'port' => (int) ($db['port'] ?? 3306),
            'database' => (string) ($db['database'] ?? ''),
            'username' => (string) ($db['username'] ?? ''),
            'password' => isset($db['password']) ? (string) $db['password'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function saveRun(array $run): void
    {
        $p = $this->state->progress();
        $p['run'] = $run;
        $this->state->saveProgress($p);
    }

    private function safeMessage(Throwable $e): string
    {
        $message = $e->getMessage();
        foreach (['database', 'queue', 'mail'] as $section) {
            foreach ($this->pending->section($section) as $field => $value) {
                if (str_contains((string) $field, 'password') && is_string($value) && $value !== '') {
                    $message = str_replace($value, '[REDACTED]', $message);
                }
            }
        }

        return SecretRedactor::redact($message, 400);
    }

    private function log(string $line): void
    {
        $existing = (string) ($this->storage->read('install.log') ?? '');
        $existing = substr($existing, -50000);
        $this->storage->write('install.log', $existing.'['.now()->toIso8601ZuluString().'] '.$line."\n");
    }

    /**
     * Shared parent domain of the API and SPA hosts (".example.com"), or null.
     *
     * @param  list<string>  $urls
     */
    public static function sessionDomain(array $urls): ?string
    {
        $hosts = array_values(array_unique(array_filter(array_map(static fn (string $u) => parse_url($u, PHP_URL_HOST) ?: null, $urls))));
        if ($hosts === [] || filter_var($hosts[0], FILTER_VALIDATE_IP) !== false || $hosts[0] === 'localhost') {
            return null;
        }
        $parts = array_map(static fn (string $h) => array_reverse(explode('.', $h)), $hosts);
        $common = [];
        for ($i = 0; ; $i++) {
            $label = $parts[0][$i] ?? null;
            if ($label === null) {
                break;
            }
            foreach ($parts as $p) {
                if (($p[$i] ?? null) !== $label) {
                    break 2;
                }
            }
            $common[] = $label;
        }
        if (count($common) < 2) {
            return null;
        }

        return '.'.implode('.', array_reverse($common));
    }

    private static function hostPort(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            return null;
        }
        $port = parse_url($url, PHP_URL_PORT);

        return $port ? $host.':'.$port : $host;
    }
}
