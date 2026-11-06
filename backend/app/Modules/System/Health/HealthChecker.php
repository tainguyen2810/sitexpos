<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Health;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use SiteXPost\Modules\AiGateway\Contracts\AiReadiness;
use SiteXPost\Modules\Billing\Contracts\BillingReadiness;
use SiteXPost\Modules\Installer\Support\InstallationState;
use SiteXPost\Modules\System\Domain\SystemBackup;
use SiteXPost\Modules\System\Support\SystemSettings;
use SiteXPost\Modules\Workflows\Support\Queues;
use Throwable;

/**
 * System Health (Phase 9 §H). Every check is local and fast (no outbound
 * HTTP in the request): the SSL expiry is measured by the scheduled monitor
 * and read back here. Never returns secrets. Cross-tenant figures are
 * read-only aggregates (counts) of platform tables.
 *
 * Status: ok | warning | critical | unknown.
 */
final class HealthChecker
{
    public const KEYS = ['application', 'database', 'redis', 'horizon', 'scheduler', 'queue', 'storage', 'ai_providers', 'ai_spending', 'payments', 'wordpress', 'gsc', 'mail', 'backup', 'ssl', 'security'];

    public function __construct(
        private readonly SystemSettings $settings,
        private readonly AiReadiness $ai,
        private readonly BillingReadiness $billing,
        private readonly InstallationState $installation,
    ) {}

    /**
     * @param  list<string>|null  $only
     * @return array{status: string, generated_at: string, checks: list<array{key: string, status: string, summary: string, details: array<string, mixed>, fix: ?string}>}
     */
    public function run(?array $only = null): array
    {
        $checks = [];
        foreach (self::KEYS as $key) {
            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }
            try {
                $checks[] = $this->{'check'.str_replace('_', '', ucwords($key, '_'))}();
            } catch (Throwable $e) {
                $checks[] = $this->result($key, 'unknown', __('system.health.check_error'), ['error' => class_basename($e)]);
            }
        }

        $statuses = array_column($checks, 'status');
        $overall = in_array('critical', $statuses, true) ? 'critical' : (in_array('warning', $statuses, true) || in_array('unknown', $statuses, true) ? 'warning' : 'ok');

        return ['status' => $overall, 'generated_at' => now()->toIso8601ZuluString(), 'checks' => $checks];
    }

    /**
     * @return array{key: string, status: string, summary: string, details: array<string, mixed>, fix: ?string}
     */
    private function checkApplication(): array
    {
        $code = (string) config('sitex.version');
        $db = $this->settings->string('system.version');
        $prod = app()->environment('production');
        $details = [
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'code_version' => $code,
            'database_version' => $db,
            'maintenance' => app()->isDownForMaintenance(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
        ];
        if (app()->isDownForMaintenance()) {
            return $this->result('application', 'warning', __('system.health.app_maintenance'), $details);
        }
        if ($prod && (bool) config('app.debug')) {
            return $this->result('application', 'critical', __('system.health.app_debug_on'), $details, __('system.fix.app_debug'));
        }
        if ($db !== null && version_compare($db, $code, '<')) {
            return $this->result('application', 'warning', __('system.health.upgrade_pending', ['from' => $db, 'to' => $code]), $details, __('system.fix.upgrade'));
        }

        return $this->result('application', 'ok', __('system.health.app_ok', ['version' => $code]), $details);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDatabase(): array
    {
        $start = microtime(true);
        DB::select('select 1');
        $ms = (int) round((microtime(true) - $start) * 1000);
        $details = ['driver' => DB::getDriverName(), 'latency_ms' => $ms];
        if (DB::getDriverName() === 'mysql') {
            $details['server_version'] = (string) (DB::selectOne('select version() as v')->v ?? '');
        }

        return $this->result('database', $ms > 200 ? 'warning' : 'ok', __('system.health.db_ok', ['ms' => $ms]), $details);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRedis(): array
    {
        if (! $this->usesRedis()) {
            return $this->result('redis', 'ok', __('system.health.redis_not_used'), ['used' => false]);
        }
        $start = microtime(true);
        Redis::connection()->ping();
        $ms = (int) round((microtime(true) - $start) * 1000);

        return $this->result('redis', 'ok', __('system.health.redis_ok', ['ms' => $ms]), ['used' => true, 'latency_ms' => $ms]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkHorizon(): array
    {
        if (config('queue.default') !== 'redis') {
            return $this->result('horizon', 'ok', __('system.health.horizon_not_used', ['driver' => (string) config('queue.default')]), ['used' => false]);
        }
        $masters = app(MasterSupervisorRepository::class)->all();
        if ($masters === []) {
            return $this->result('horizon', 'critical', __('system.health.horizon_inactive'), ['used' => true, 'masters' => 0], __('system.fix.horizon'));
        }
        $paused = collect($masters)->contains(fn ($m) => ($m->status ?? '') === 'paused');

        return $this->result('horizon', $paused ? 'warning' : 'ok', $paused ? __('system.health.horizon_paused') : __('system.health.horizon_running'), ['used' => true, 'masters' => count($masters)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkScheduler(): array
    {
        $beat = $this->settings->string('system.heartbeat_at');
        $age = $beat !== null ? (int) abs(now()->diffInSeconds(Carbon::parse($beat))) : null;
        $stale = (int) config('sitex.system.heartbeat_stale_seconds', 180);
        $details = ['last_heartbeat_at' => $beat, 'age_seconds' => $age];
        if ($age === null) {
            return $this->result('scheduler', 'critical', __('system.health.scheduler_never'), $details, __('system.fix.scheduler'));
        }
        if ($age > $stale) {
            return $this->result('scheduler', 'critical', __('system.health.scheduler_stale', ['minutes' => (int) ceil($age / 60)]), $details, __('system.fix.scheduler'));
        }

        return $this->result('scheduler', 'ok', __('system.health.scheduler_ok'), $details);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkQueue(): array
    {
        $sizes = [];
        $total = 0;
        foreach (Queues::ALL as $name) {
            try {
                $size = Queue::size($name);
                $sizes[$name] = $size;
                $total += $size;
            } catch (Throwable) {
                $sizes[$name] = null;
            }
        }
        $failed = (int) DB::table('failed_jobs')->count();
        $failed24 = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $details = ['connection' => config('queue.default'), 'backlog' => $sizes, 'backlog_total' => $total, 'failed_jobs' => $failed, 'failed_24h' => $failed24];

        $status = 'ok';
        if ($total >= (int) config('sitex.system.queue_backlog_warning', 500) || $failed24 >= (int) config('sitex.system.failed_jobs_warning', 10)) {
            $status = 'warning';
        }

        return $this->result('queue', $status, __('system.health.queue_summary', ['backlog' => $total, 'failed' => $failed24]), $details, $status === 'ok' ? null : __('system.fix.queue'));
    }

    /**
     * @return array<string, mixed>
     */
    private function checkStorage(): array
    {
        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());
        if ($total === false || $free === false || $total <= 0) {
            return $this->result('storage', 'unknown', __('system.health.storage_unknown'), []);
        }
        $used = (int) round((1 - $free / $total) * 100);
        $details = ['used_percent' => $used, 'free_gb' => round($free / 1024 ** 3, 1), 'total_gb' => round($total / 1024 ** 3, 1)];
        $status = $used >= (int) config('sitex.system.disk_critical_percent', 95) ? 'critical' : ($used >= (int) config('sitex.system.disk_warning_percent', 85) ? 'warning' : 'ok');

        return $this->result('storage', $status, __('system.health.storage_summary', ['used' => $used, 'free' => $details['free_gb']]), $details, $status === 'ok' ? null : __('system.fix.storage'));
    }

    /**
     * @return array<string, mixed>
     */
    private function checkAiProviders(): array
    {
        $providers = array_values(array_filter($this->ai->providers(), fn (array $p) => $p['code'] !== 'fake'));
        $ready = array_values(array_filter($providers, fn (array $p) => $p['ready']));
        $open = array_values(array_filter($providers, fn (array $p) => $p['circuit'] === 'open'));
        $details = ['providers' => array_map(fn (array $p) => ['code' => $p['code'], 'enabled' => $p['enabled'], 'has_key' => $p['has_key'], 'circuit' => $p['circuit'], 'ready' => $p['ready']], $providers)];
        if ($ready === []) {
            return $this->result('ai_providers', 'warning', __('system.health.ai_none_ready'), $details, __('system.fix.ai_providers'));
        }
        if ($open !== []) {
            return $this->result('ai_providers', 'warning', __('system.health.ai_circuit_open', ['providers' => implode(', ', array_column($open, 'code'))]), $details);
        }

        return $this->result('ai_providers', 'ok', __('system.health.ai_ready', ['count' => count($ready)]), $details);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkAiSpending(): array
    {
        $today = $this->ai->spendSince(now()->startOfDay());
        $month = $this->ai->spendSince(now()->startOfMonth());
        $req = $this->ai->requestsSince(now()->subDay());
        $rate = $req['total'] > 0 ? round($req['failed'] / $req['total'] * 100, 1) : 0.0;
        $details = ['today_micro_usd' => $today, 'month_micro_usd' => $month, 'requests_24h' => $req['total'], 'failed_24h' => $req['failed'], 'failure_rate_percent' => $rate];
        $status = $req['total'] >= 20 && $rate >= 20 ? 'warning' : 'ok';

        return $this->result('ai_spending', $status, __('system.health.ai_spend', ['today' => number_format($today / 1_000_000, 2), 'month' => number_format($month / 1_000_000, 2), 'rate' => $rate]), $details);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkPayments(): array
    {
        $providers = $this->billing->paymentProviders();
        $enabled = array_values(array_filter($providers, fn (array $p) => $p['enabled']));
        $hook = $this->billing->webhookHealth(now()->subDay());
        $details = ['enabled_providers' => array_column($enabled, 'code'), 'webhooks_24h' => $hook];
        if ($enabled === []) {
            return $this->result('payments', 'warning', __('system.health.payments_none'), $details, __('system.fix.payments'));
        }
        if ($hook['rejected'] > 0) {
            return $this->result('payments', 'warning', __('system.health.payments_rejected', ['count' => $hook['rejected']]), $details);
        }

        return $this->result('payments', 'ok', __('system.health.payments_ok', ['count' => count($enabled)]), $details);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkWordpress(): array
    {
        $counts = DB::table('sites')->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c)->all();
        $failedPublish = (int) DB::table('publishing_jobs')->where('status', 'FAILED')->where('updated_at', '>=', now()->subDay())->count();
        $details = ['sites' => $counts, 'failed_publishing_24h' => $failedPublish];
        $degraded = (int) ($counts['degraded'] ?? 0);
        $status = $degraded > 0 || $failedPublish > 0 ? 'warning' : 'ok';

        return $this->result('wordpress', $status, __('system.health.wp_summary', ['connected' => (int) ($counts['connected'] ?? 0), 'degraded' => $degraded, 'failed' => $failedPublish]), $details);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkGsc(): array
    {
        $counts = DB::table('search_console_connections')->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c)->all();
        $configured = (string) config('services.google.client_id') !== '' && (string) config('services.google.client_secret') !== '';
        $errors = (int) ($counts['error'] ?? 0) + (int) ($counts['revoked'] ?? 0);
        $details = ['oauth_configured' => $configured, 'connections' => $counts];
        if (! $configured) {
            return $this->result('gsc', 'warning', __('system.health.gsc_not_configured'), $details, __('system.fix.gsc'));
        }

        return $this->result('gsc', $errors > 0 ? 'warning' : 'ok', __('system.health.gsc_summary', ['active' => (int) ($counts['active'] ?? 0), 'errors' => $errors]), $details);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkMail(): array
    {
        $mailer = (string) config('mail.default');
        $details = ['mailer' => $mailer, 'last_test' => $this->settings->get('mail.last_test')];
        if (in_array($mailer, ['log', 'array'], true)) {
            return $this->result('mail', app()->environment('production') ? 'warning' : 'ok', __('system.health.mail_log'), $details, __('system.fix.mail'));
        }

        return $this->result('mail', 'ok', __('system.health.mail_ok', ['mailer' => $mailer]), $details);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkBackup(): array
    {
        $last = SystemBackup::query()->where('status', 'succeeded')->orderByDesc('finished_at')->first();
        $lastAny = SystemBackup::query()->orderByDesc('created_at')->first();
        $maxAge = (int) config('sitex.system.backup_max_age_hours', 26);
        $offsite = (string) config('sitex.system.backup.disk') !== '';
        $details = [
            'last_success_at' => $last?->finished_at?->toIso8601ZuluString(),
            'last_status' => $lastAny?->status,
            'offsite_configured' => $offsite,
            'encryption_configured' => (string) config('sitex.system.backup.encryption_key') !== '',
            'schedule_enabled' => (bool) $this->settings->get('backup.schedule_enabled'),
            'last_verified' => $last?->verify_status,
        ];
        if ($lastAny !== null && $lastAny->status === 'failed') {
            return $this->result('backup', 'critical', __('system.health.backup_failed'), $details, __('system.fix.backup'));
        }
        if ($last === null) {
            return $this->result('backup', 'warning', __('system.health.backup_never'), $details, __('system.fix.backup'));
        }
        $age = (int) abs(now()->diffInHours($last->finished_at));
        if ($age > $maxAge) {
            return $this->result('backup', 'warning', __('system.health.backup_old', ['hours' => $age]), $details, __('system.fix.backup'));
        }
        if (! $offsite) {
            return $this->result('backup', 'warning', __('system.health.backup_local_only'), $details, __('system.fix.backup_offsite'));
        }

        return $this->result('backup', 'ok', __('system.health.backup_ok', ['hours' => $age]), $details);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkSsl(): array
    {
        $url = (string) config('app.url');
        if (! str_starts_with($url, 'https://')) {
            return $this->result('ssl', app()->environment('production') ? 'critical' : 'warning', __('system.health.ssl_http'), ['app_url' => $url], __('system.fix.https'));
        }
        $ssl = $this->settings->get('system.ssl');
        if (! is_array($ssl) || ! isset($ssl['expires_at'])) {
            return $this->result('ssl', 'unknown', __('system.health.ssl_unknown'), ['app_url' => $url]);
        }
        $days = (int) floor((strtotime((string) $ssl['expires_at']) - time()) / 86400);
        $details = ['app_url' => $url, 'expires_at' => $ssl['expires_at'], 'days_left' => $days, 'checked_at' => $ssl['checked_at'] ?? null];
        $status = $days <= 3 ? 'critical' : ($days <= (int) config('sitex.system.ssl_warning_days', 14) ? 'warning' : 'ok');

        return $this->result('ssl', $status, __('system.health.ssl_days', ['days' => $days]), $details, $status === 'ok' ? null : __('system.fix.ssl'));
    }

    /**
     * @return array<string, mixed>
     */
    private function checkSecurity(): array
    {
        $items = $this->securityItems();
        $failed = array_keys(array_filter($items, fn (bool $ok) => ! $ok));
        $critical = array_intersect($failed, ['app_debug_off', 'app_key_set', 'installer_locked']);
        $status = $critical !== [] ? 'critical' : ($failed !== [] ? 'warning' : 'ok');

        return $this->result('security', $status, $failed === [] ? __('system.health.security_ok') : __('system.health.security_issues', ['count' => count($failed)]), ['items' => $items], $failed === [] ? null : __('system.fix.security'));
    }

    /**
     * Security checklist (Setup Wizard step "Security" + health).
     *
     * @return array<string, bool>
     */
    public function securityItems(): array
    {
        $prod = app()->environment('production');
        $key = (string) config('app.key');

        return [
            'app_env_production' => $prod,
            'app_debug_off' => ! (bool) config('app.debug'),
            'app_key_set' => $key !== '' && strlen((string) base64_decode(str_replace('base64:', '', $key), true)) === 32,
            'https' => str_starts_with((string) config('app.url'), 'https://'),
            'secure_cookies' => (bool) config('session.secure') || ! $prod,
            'session_http_only' => (bool) config('session.http_only', true),
            'installer_locked' => $this->installation->isInstalled(),
            'env_not_world_readable' => ! is_file(app()->environmentFilePath()) || (fileperms(app()->environmentFilePath()) & 0004) === 0,
            'scheduler_running' => $this->checkScheduler()['status'] === 'ok',
            'backup_configured' => (string) config('sitex.system.backup.encryption_key') !== '' && (bool) $this->settings->get('backup.schedule_enabled'),
            'super_admin_2fa' => ! DB::table('users')->where('platform_role', 'SUPER_ADMIN')->whereNull('two_factor_confirmed_at')->exists(),
        ];
    }

    private function usesRedis(): bool
    {
        return config('queue.default') === 'redis' || config('cache.default') === 'redis' || config('session.driver') === 'redis';
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{key: string, status: string, summary: string, details: array<string, mixed>, fix: ?string}
     */
    private function result(string $key, string $status, string $summary, array $details, ?string $fix = null): array
    {
        return ['key' => $key, 'status' => $status, 'summary' => $summary, 'details' => $details, 'fix' => $fix];
    }
}
