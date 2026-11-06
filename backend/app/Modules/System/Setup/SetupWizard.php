<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Setup;

use SiteXPost\Modules\AiGateway\Contracts\AiReadiness;
use SiteXPost\Modules\Billing\Contracts\BillingReadiness;
use SiteXPost\Modules\System\Health\HealthChecker;
use SiteXPost\Modules\System\Support\PluginPackage;
use SiteXPost\Modules\System\Support\SystemSettings;

/**
 * Post-installation Setup Wizard (Phase 9 §F). Each step's status is
 * computed from the real configuration — never from "the user clicked next":
 * NOT_STARTED | IN_PROGRESS | COMPLETE | WARNING | BLOCKED.
 * Configuration itself is reused from Phase 3 (AI Gateway) and Phase 7
 * (Billing) screens; this wizard only adds what had no screen yet.
 */
final class SetupWizard
{
    /** AI task routes the wizard asks to assign (Phase 3 keys). */
    public const AI_TASKS = [
        'topic_discovery' => 'topics.ideas',
        'research' => 'writer.source_analysis',
        'outline' => 'writer.outline',
        'writing' => 'content.draft',
        'fact_check' => 'verification.claims',
        'seo_audit' => 'writer.seo_review',
        'image' => 'media.image',
    ];

    public const STEPS = ['general', 'ai_providers', 'ai_models', 'email', 'plans', 'payments', 'credits', 'google', 'wordpress', 'queue', 'security', 'final'];

    /** Steps that must be COMPLETE before the platform is declared ready. */
    private const REQUIRED = ['general', 'ai_providers', 'ai_models', 'email', 'plans', 'payments', 'credits', 'queue', 'security'];

    /** Required steps for which a WARNING (non-critical) still allows "ready with warnings". */
    private const WARNING_TOLERANT = ['queue', 'security', 'ai_models'];

    public const STANDARD_PLANS = ['free' => 'Free', 'starter' => 'Starter', 'pro' => 'Pro', 'business' => 'Business'];

    public function __construct(
        private readonly SystemSettings $settings,
        private readonly AiReadiness $ai,
        private readonly BillingReadiness $billing,
        private readonly HealthChecker $health,
        private readonly PluginPackage $plugin,
    ) {}

    /**
     * @return array{steps: list<array<string, mixed>>, readiness: string, completed_at: ?string, progress_percent: int}
     */
    public function overview(): array
    {
        $steps = [
            $this->general(),
            $providers = $this->aiProviders(),
            $this->aiModels($providers['status']),
            $this->email(),
            $this->plans(),
            $this->payments(),
            $this->credits(),
            $this->google(),
            $this->wordpress(),
            $this->queue(),
            $this->security(),
        ];
        $readiness = $this->readiness($steps);
        $steps[] = ['key' => 'final', 'status' => $readiness === 'NOT_READY' ? 'IN_PROGRESS' : ($readiness === 'READY' ? 'COMPLETE' : 'WARNING'), 'required' => true, 'data' => [
            'readiness' => $readiness,
            'blocking' => $this->blocking($steps),
            'completed_at' => $this->settings->string('setup.completed_at'),
        ]];
        $done = count(array_filter($steps, fn (array $s) => $s['status'] === 'COMPLETE'));

        return [
            'steps' => $steps,
            'readiness' => $readiness,
            'completed_at' => $this->settings->string('setup.completed_at'),
            'progress_percent' => (int) round($done / count($steps) * 100),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function readiness(array $steps): string
    {
        $warnings = false;
        foreach ($steps as $s) {
            if (! in_array($s['key'], self::REQUIRED, true)) {
                continue;
            }
            if ($s['status'] === 'COMPLETE') {
                continue;
            }
            if ($s['status'] === 'WARNING' && in_array($s['key'], self::WARNING_TOLERANT, true)) {
                $warnings = true;

                continue;
            }

            return 'NOT_READY';
        }

        return $warnings ? 'READY_WITH_WARNINGS' : 'READY';
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<string>
     */
    private function blocking(array $steps): array
    {
        $out = [];
        foreach ($steps as $s) {
            if (in_array($s['key'], self::REQUIRED, true) && $s['status'] !== 'COMPLETE'
                && ! ($s['status'] === 'WARNING' && in_array($s['key'], self::WARNING_TOLERANT, true))) {
                $out[] = (string) $s['key'];
            }
        }

        return $out;
    }

    public function isReady(): bool
    {
        return $this->overview()['readiness'] !== 'NOT_READY';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{key: string, status: string, required: bool, data: array<string, mixed>}
     */
    private function step(string $key, string $status, array $data = []): array
    {
        return ['key' => $key, 'status' => $status, 'required' => in_array($key, self::REQUIRED, true), 'data' => $data];
    }

    /**
     * @return array<string, mixed>
     */
    private function general(): array
    {
        $g = $this->settings->publicGroup('general');
        // brand_name has a default, so it alone does not mean the step was started.
        $filled = array_filter([$g['support_email'] ?? null, $g['company_name'] ?? null, $g['contact_phone'] ?? null, $g['logo_path'] ?? null], fn ($v) => $v !== null && $v !== '');
        $status = ($g['support_email'] ?? null) && ($g['brand_name'] ?? null) ? 'COMPLETE' : ($filled === [] ? 'NOT_STARTED' : 'IN_PROGRESS');

        return $this->step('general', $status, $g + ['app_url' => config('app.url'), 'logo_url' => $this->logoUrl()]);
    }

    private function logoUrl(): ?string
    {
        $path = $this->settings->string('general.logo_path');

        return $path !== null ? rtrim((string) config('app.url'), '/').'/storage/'.ltrim($path, '/') : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function aiProviders(): array
    {
        $providers = array_values(array_filter($this->ai->providers(), fn (array $p) => $p['code'] !== 'fake'));
        $ready = array_filter($providers, fn (array $p) => $p['ready']);
        $withKey = array_filter($providers, fn (array $p) => $p['has_key']);
        $status = $ready !== [] ? 'COMPLETE' : ($withKey !== [] ? 'WARNING' : 'NOT_STARTED');

        return $this->step('ai_providers', $status, ['providers' => $providers]);
    }

    /**
     * @return array<string, mixed>
     */
    private function aiModels(string $providerStatus): array
    {
        $routes = $this->ai->routes(array_values(self::AI_TASKS));
        $byKey = [];
        foreach ($routes as $r) {
            $byKey[$r['task_key']] = $r;
        }
        $tasks = [];
        foreach (self::AI_TASKS as $name => $key) {
            $tasks[] = ['task' => $name] + ($byKey[$key] ?? ['task_key' => $key, 'ready' => false]);
        }
        $readyCount = count(array_filter($tasks, fn (array $t) => $t['ready']));
        $status = match (true) {
            $providerStatus !== 'COMPLETE' => 'BLOCKED',
            $readyCount === count($tasks) => 'COMPLETE',
            $readyCount === 0 => 'NOT_STARTED',
            default => 'WARNING',
        };

        return $this->step('ai_models', $status, ['tasks' => $tasks]);
    }

    /**
     * @return array<string, mixed>
     */
    private function email(): array
    {
        $mail = $this->settings->publicGroup('mail');
        $lastTest = is_array($mail['last_test'] ?? null) ? $mail['last_test'] : null;
        $mailer = (string) config('mail.default');
        $configured = $mail['mailer'] === 'smtp' || ! in_array($mailer, ['log', 'array'], true);
        $status = match (true) {
            ! $configured => 'NOT_STARTED',
            $lastTest !== null && ($lastTest['ok'] ?? false) === true => 'COMPLETE',
            $lastTest !== null && ($lastTest['ok'] ?? null) === false => 'WARNING',
            default => 'IN_PROGRESS',
        };

        return $this->step('email', $status, $mail + ['active_mailer' => $mailer, 'source' => $mail['mailer'] === 'smtp' ? 'settings' : 'env']);
    }

    /**
     * @return array<string, mixed>
     */
    private function plans(): array
    {
        $plans = array_values(array_filter($this->billing->plans(), fn (array $p) => ! $p['is_demo']));
        $sellable = array_filter($plans, fn (array $p) => $p['status'] === 'active' && $p['published']);
        $status = $sellable !== [] ? 'COMPLETE' : ($plans !== [] ? 'IN_PROGRESS' : 'NOT_STARTED');
        $existing = array_column($plans, 'code');

        return $this->step('plans', $status, [
            'plans' => $plans,
            'missing_standard' => array_values(array_diff(array_keys(self::STANDARD_PLANS), $existing)),
            'enforcement' => $this->billing->settings()['enforcement'] ?? 'off',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payments(): array
    {
        $providers = $this->billing->paymentProviders();
        $enabled = array_values(array_filter($providers, fn (array $p) => $p['enabled']));
        $good = array_filter($enabled, fn (array $p) => $p['configured'] && $p['qr_ok'] !== false);
        $status = $good !== [] ? 'COMPLETE' : ($enabled !== [] ? 'WARNING' : ($providers !== [] ? 'IN_PROGRESS' : 'NOT_STARTED'));

        return $this->step('payments', $status, ['providers' => $providers]);
    }

    /**
     * @return array<string, mixed>
     */
    private function credits(): array
    {
        $policy = $this->billing->creditPolicy();
        $settings = $this->billing->settings();
        $rate = $settings['usd_to_currency_rate'] ?? null;
        $status = $policy['configured'] && $rate ? 'COMPLETE' : ($policy['configured'] || $rate ? 'WARNING' : 'NOT_STARTED');

        return $this->step('credits', $status, ['policy' => $policy, 'settings' => $settings]);
    }

    /**
     * @return array<string, mixed>
     */
    private function google(): array
    {
        $g = $this->settings->publicGroup('google');
        $id = (string) config('services.google.client_id');
        $secret = (string) config('services.google.client_secret');
        $lastTest = is_array($g['last_test'] ?? null) ? $g['last_test'] : null;
        $status = match (true) {
            $id === '' || $secret === '' => 'NOT_STARTED',
            $lastTest !== null && ($lastTest['ok'] ?? false) === true => 'COMPLETE',
            $lastTest !== null => 'WARNING',
            default => 'IN_PROGRESS',
        };

        return $this->step('google', $status, [
            'client_id' => $id !== '' ? $id : null,
            'client_secret_configured' => $secret !== '',
            'source' => $g['client_id'] !== null ? 'settings' : ($id !== '' ? 'env' : null),
            'redirect_uri' => (string) config('services.google.redirect_uri'),
            'scopes' => ['https://www.googleapis.com/auth/webmasters.readonly', 'openid', 'email'],
            'ga4_enabled' => (bool) ($g['ga4_enabled'] ?? false),
            'last_test' => $lastTest,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function wordpress(): array
    {
        $pkg = $this->plugin->info();
        $ack = in_array('wordpress', (array) $this->settings->get('setup.acknowledged'), true);
        $status = ! $pkg['available'] ? 'WARNING' : ($ack ? 'COMPLETE' : 'IN_PROGRESS');

        return $this->step('wordpress', $status, $pkg + [
            'acknowledged' => $ack,
            'requirements' => ['wordpress' => '6.4+', 'php' => '7.4+', 'https' => true],
            'connector_url' => (string) config('sitex.connector.public_url'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        $result = $this->health->run(['redis', 'horizon', 'scheduler', 'queue']);
        $statuses = array_column($result['checks'], 'status');
        $status = in_array('critical', $statuses, true) ? 'BLOCKED' : (in_array('warning', $statuses, true) || in_array('unknown', $statuses, true) ? 'WARNING' : 'COMPLETE');

        return $this->step('queue', $status, ['checks' => $result['checks']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function security(): array
    {
        $items = $this->health->securityItems();
        $critical = ['app_debug_off', 'app_key_set', 'installer_locked'];
        $failed = array_keys(array_filter($items, fn (bool $ok) => ! $ok));
        $status = array_intersect($failed, $critical) !== [] ? 'BLOCKED' : ($failed !== [] ? 'WARNING' : 'COMPLETE');

        return $this->step('security', $status, ['items' => $items, 'failed' => $failed]);
    }
}
