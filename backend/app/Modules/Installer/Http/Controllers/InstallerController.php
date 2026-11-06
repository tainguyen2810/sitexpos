<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Installer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Installer\Actions\RunInstallation;
use SiteXPost\Modules\Installer\Support\DatabaseProbe;
use SiteXPost\Modules\Installer\Support\DatabaseTarget;
use SiteXPost\Modules\Installer\Support\DeploymentTemplates;
use SiteXPost\Modules\Installer\Support\EnvironmentChecker;
use SiteXPost\Modules\Installer\Support\EnvWriter;
use SiteXPost\Modules\Installer\Support\InstallationState;
use SiteXPost\Modules\Installer\Support\InstallerStorage;
use SiteXPost\Modules\Installer\Support\PendingConfig;
use SiteXPost\Modules\Installer\Support\RedisProbe;
use SiteXPost\Modules\Installer\Support\SetupToken;

/**
 * Web installer API (INSTALLATION-WIZARD.md). Every route is behind
 * InstallerGuard (setup token + lock + rate limits). Secrets are accepted
 * write-only and never echoed back.
 */
final class InstallerController
{
    public function __construct(
        private readonly InstallationState $state,
        private readonly PendingConfig $pending,
        private readonly InstallerStorage $storage,
    ) {}

    public function page(Request $request, SetupToken $token): Response
    {
        $installed = $this->state->isInstalled();
        if (! $installed) {
            $token->ensureIssued();
        }

        return response()->view('installer::installer', [
            'installed' => $installed,
            'version' => (string) config('sitex.version'),
            'adminUrl' => (string) config('sitex.admin_url'),
            'tokenFile' => 'backend/storage/app/installer/'.SetupToken::FILE_PLAIN,
            'deployment' => (string) config('sitex.deployment'),
        ], $installed ? 403 : 200)->header('Cache-Control', 'no-store')
            ->header('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'");
    }

    public function state(): JsonResponse
    {
        $progress = $this->state->progress();

        return new JsonResponse(['data' => [
            'installed' => false,
            'version' => config('sitex.version'),
            'steps' => $progress['steps'] ?? [],
            'config' => $this->pending->publicView(),
            'run' => $this->publicRun($progress['run'] ?? null),
            'templates' => config('sitex.deployment') === 'docker' ? [] : DeploymentTemplates::NAMES,
            'deployment' => config('sitex.deployment'),
            'defaults' => array_filter((array) config('sitex.installer.defaults'), static fn ($v): bool => is_scalar($v) && (string) $v !== ''),
        ]]);
    }

    public function environment(Request $request, EnvironmentChecker $checker): JsonResponse
    {
        $result = $checker->run($request);
        if ($result['can_continue']) {
            $this->state->markStep('environment');
        }

        return new JsonResponse(['data' => $result]);
    }

    public function testDatabase(Request $request, DatabaseTarget $target): JsonResponse
    {
        $db = $this->validatedDatabase($request);

        return new JsonResponse(['data' => $this->describeDatabase($target->inspect($db), $db)]);
    }

    public function saveDatabase(Request $request, DatabaseTarget $target): JsonResponse
    {
        $db = $this->validatedDatabase($request);
        $info = $this->describeDatabase($target->inspect($db), $db);

        if (! $info['connected']) {
            throw new ApiException(422, 'database_'.($info['error_code'] ?? 'connection_failed'), __('installer.db.'.($info['error_code'] ?? 'connection_failed')));
        }
        if (! $info['version_ok']) {
            throw new ApiException(422, 'database_version', __('installer.db.version'));
        }
        if (! $info['empty'] && ! $info['resumable']) {
            throw new ApiException(409, 'database_not_empty', __('installer.db.not_empty'));
        }

        $this->pending->put('database', $db);
        $this->state->markStep('database');

        return new JsonResponse(['data' => $info]);
    }

    public function saveApplication(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_name' => ['required', 'string', 'max:80'],
            'app_url' => ['required', 'url:http,https', 'max:190'],
            'frontend_url' => ['required', 'url:http,https', 'max:190'],
            'admin_url' => ['required', 'url:http,https', 'max:190'],
            'locale' => ['required', Rule::in(['vi', 'en'])],
            'timezone' => ['required', 'timezone:all'],
            'mail.mailer' => ['required', Rule::in(['log', 'smtp'])],
            'mail.host' => ['exclude_unless:mail.mailer,smtp', 'required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'mail.port' => ['exclude_unless:mail.mailer,smtp', 'required', 'integer', 'between:1,65535'],
            'mail.encryption' => ['exclude_unless:mail.mailer,smtp', 'required', Rule::in(['tls', 'ssl', 'none'])],
            'mail.username' => ['exclude_unless:mail.mailer,smtp', 'nullable', 'string', 'max:190'],
            'mail.password' => ['exclude_unless:mail.mailer,smtp', 'nullable', 'string', 'max:255'],
            'mail.from_address' => ['exclude_unless:mail.mailer,smtp', 'required', 'email:rfc', 'max:190'],
            'mail.from_name' => ['exclude_unless:mail.mailer,smtp', 'nullable', 'string', 'max:80'],
        ]);

        $mail = (array) ($data['mail'] ?? ['mailer' => 'log']);
        if (($mail['mailer'] ?? 'log') === 'smtp' && ($mail['password'] ?? null) === null) {
            $mail['password'] = $this->pending->section('mail')['password'] ?? null; // keep the saved one
        }
        unset($data['mail']);
        foreach (['app_url', 'frontend_url', 'admin_url'] as $k) {
            $data[$k] = rtrim((string) $data[$k], '/');
        }

        $this->pending->put('application', $data);
        $this->pending->put('mail', $mail);
        $this->state->markStep('application');

        return new JsonResponse(['data' => [
            'saved' => true,
            'warnings' => array_values(array_filter([
                ! str_starts_with($data['app_url'], 'https://') ? 'app_url_not_https' : null,
                $request->getSchemeAndHttpHost() !== $data['app_url'] ? 'app_url_differs_from_current' : null,
            ])),
        ]]);
    }

    public function testRedis(Request $request, RedisProbe $probe): JsonResponse
    {
        $data = $this->validatedRedis($request);

        return new JsonResponse(['data' => $probe->ping($data['redis_host'], (int) $data['redis_port'], $data['redis_password'] ?? null)]);
    }

    public function saveQueue(Request $request, RedisProbe $probe): JsonResponse
    {
        $data = $request->validate(['driver' => ['required', Rule::in(['redis', 'database'])]]);
        $queue = ['driver' => $data['driver']];
        $redis = ['connected' => false, 'error_code' => null, 'version' => null];

        if ($data['driver'] === 'redis') {
            $r = $this->validatedRedis($request);
            $redis = $probe->ping($r['redis_host'], (int) $r['redis_port'], $r['redis_password'] ?? null);
            if (! $redis['connected']) {
                throw new ApiException(422, 'redis_'.$redis['error_code'], __('installer.redis.failed'));
            }
            $queue += $r;
        }

        $this->pending->put('queue', $queue);
        $this->state->markStep('queue');

        return new JsonResponse(['data' => [
            'redis' => $redis,
            'horizon_possible' => $data['driver'] === 'redis' && extension_loaded('pcntl') && extension_loaded('redis'),
            'templates' => config('sitex.deployment') === 'docker' ? [] : DeploymentTemplates::NAMES,
        ]]);
    }

    public function saveAdmin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'max:200', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        $local = strtolower((string) strstr((string) $data['email'], '@', true));
        if ($local !== '' && strlen($local) >= 4 && str_contains(strtolower((string) $data['password']), $local)) {
            throw ValidationException::withMessages(['password' => [__('installer.admin.password_contains_email')]]);
        }

        $this->pending->put('admin', [
            'name' => $data['name'],
            'email' => mb_strtolower((string) $data['email']),
            'password_hash' => Hash::make((string) $data['password']),
        ]);
        $this->state->markStep('admin');

        return new JsonResponse(['data' => ['saved' => true]]);
    }

    public function run(RunInstallation $action): JsonResponse
    {
        try {
            $result = $action->handle();
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'installation_in_progress') {
                throw ApiException::conflict('installation_in_progress', __('installer.in_progress'));
            }
            throw $e;
        }

        $data = $result + ['redirect' => null];
        if ($result['status'] === 'completed') {
            $data['redirect'] = $this->adminUrlFromEnv().'/setup';
        } else {
            $data['message'] = __('installer.errors.'.($result['error_code'] ?? 'step_failed'));
        }

        return new JsonResponse(['data' => $data], $result['status'] === 'completed' ? 200 : 500);
    }

    public function template(Request $request, string $name, DeploymentTemplates $templates): Response
    {
        $app = $this->pending->section('application');
        $host = is_string($app['app_url'] ?? null) ? parse_url($app['app_url'], PHP_URL_HOST) : null;
        $content = $templates->render($name, is_string($host) ? $host : $request->getHost());
        if ($content === null) {
            throw ApiException::notFound();
        }
        $file = $name === 'cron' ? 'sitexpost-cron.txt' : ($name === 'nginx-api' ? 'sitexpost-nginx.conf' : 'sitexpost-'.$name.'.conf');

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function diagnostics(Request $request, EnvironmentChecker $checker): JsonResponse
    {
        $log = (string) ($this->storage->read('install.log') ?? '');
        $lines = array_slice(array_filter(explode("\n", $log)), -100);

        return new JsonResponse(['data' => [
            'run' => $this->publicRun($this->state->progress()['run'] ?? null),
            'environment' => $checker->run($request)['summary'],
            'log' => array_values($lines),
        ]]);
    }

    private function adminUrlFromEnv(): string
    {
        // The pending config is cleared on success; .env now holds ADMIN_URL.
        $env = app(EnvWriter::class);
        $values = $env->parse((string) @file_get_contents($env->path()));

        return rtrim((string) ($values['ADMIN_URL'] ?? config('sitex.admin_url')), '/');
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: ?string}
     */
    private function validatedDatabase(Request $request): array
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'username' => ['required', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);
        $password = $data['password'] ?? null;
        if ($password === null && ! $request->boolean('clear_password')) {
            $password = $this->pending->section('database')['password'] ?? null; // keep the saved one
        }

        return [
            'host' => (string) $data['host'],
            'port' => (int) $data['port'],
            'database' => (string) $data['database'],
            'username' => (string) $data['username'],
            'password' => $password,
        ];
    }

    /**
     * @return array{redis_host: string, redis_port: int, redis_password: ?string}
     */
    private function validatedRedis(Request $request): array
    {
        $data = $request->validate([
            'redis_host' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-:\[\]\/]+$/'],
            'redis_port' => ['required', 'integer', 'between:1,65535'],
            'redis_password' => ['nullable', 'string', 'max:255'],
        ]);
        $password = $data['redis_password'] ?? null;
        if ($password === null) {
            $password = $this->pending->section('queue')['redis_password'] ?? null;
        }

        return ['redis_host' => (string) $data['redis_host'], 'redis_port' => (int) $data['redis_port'], 'redis_password' => $password];
    }

    /**
     * @param  array{connected: bool, error_code: ?string, server_version: ?string, version_ok: bool, table_count: int, has_sitex_tables: bool, empty: bool, can_create: bool}  $info
     * @param  array{host: string, port: int, database: string, username: string, password: ?string}  $db
     * @return array<string, mixed>
     */
    private function describeDatabase(array $info, array $db): array
    {
        $run = $this->state->progress()['run'] ?? [];
        $resumable = ! $info['empty'] && is_array($run)
            && ($run['db_fingerprint'] ?? null) === DatabaseProbe::fingerprint($db)
            && ($run['migrations_started'] ?? false) === true;

        return $info + [
            'resumable' => $resumable,
            'message' => $info['connected'] ? null : __('installer.db.'.($info['error_code'] ?? 'connection_failed')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicRun(mixed $run): ?array
    {
        if (! is_array($run)) {
            return null;
        }

        return [
            'status' => $run['status'] ?? null,
            'attempts' => $run['attempts'] ?? 0,
            'steps' => $run['steps'] ?? [],
            'failed_step' => $run['failed_step'] ?? null,
            'error_code' => $run['error_code'] ?? null,
            'error' => $run['error'] ?? null,
            'started_at' => $run['started_at'] ?? null,
            'finished_at' => $run['finished_at'] ?? null,
        ];
    }
}
