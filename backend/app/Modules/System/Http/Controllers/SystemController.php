<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use SiteXPost\Kernel\Http\ApiException;
use SiteXPost\Modules\Audit\Audit;
use SiteXPost\Modules\Billing\Contracts\BillingReadiness;
use SiteXPost\Modules\System\Domain\SystemAlert;
use SiteXPost\Modules\System\Health\HealthChecker;
use SiteXPost\Modules\System\Http\Requests\AcknowledgeRequest;
use SiteXPost\Modules\System\Http\Requests\AlertSettingsRequest;
use SiteXPost\Modules\System\Http\Requests\BackupSettingsRequest;
use SiteXPost\Modules\System\Http\Requests\GeneralSettingsRequest;
use SiteXPost\Modules\System\Http\Requests\GoogleSettingsRequest;
use SiteXPost\Modules\System\Http\Requests\LogoRequest;
use SiteXPost\Modules\System\Http\Requests\MailSettingsRequest;
use SiteXPost\Modules\System\Http\Requests\TestEmailRequest;
use SiteXPost\Modules\System\Jobs\SendSystemAlert;
use SiteXPost\Modules\System\Jobs\SendTestEmail;
use SiteXPost\Modules\System\Jobs\TestGoogleOAuth;
use SiteXPost\Modules\System\Setup\SetupWizard;
use SiteXPost\Modules\System\Support\PluginPackage;
use SiteXPost\Modules\System\Support\SystemSettings;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Super Admin: System Health, Setup Wizard and platform settings (Phase 9).
 * Secrets are write-only; tests that reach the network run as queued jobs
 * (202) and store their result for the page to poll.
 */
final class SystemController
{
    public function __construct(private readonly SystemSettings $settings) {}

    public function health(HealthChecker $health): JsonResponse
    {
        return new JsonResponse(['data' => $health->run()]);
    }

    public function alerts(): JsonResponse
    {
        return new JsonResponse(['data' => [
            'settings' => $this->settings->publicGroup('alerts'),
            'history' => SystemAlert::query()->orderByDesc('created_at')->limit(50)->get()->map(fn (SystemAlert $a) => [
                'id' => $a->id, 'check_key' => $a->check_key, 'severity' => $a->severity, 'summary' => $a->summary,
                'channels' => $a->channels ?? (object) [], 'created_at' => $a->created_at?->toIso8601ZuluString(),
            ])->all(),
        ]]);
    }

    public function setup(SetupWizard $wizard): JsonResponse
    {
        return new JsonResponse(['data' => $wizard->overview()]);
    }

    public function updateGeneral(GeneralSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $values = [];
        foreach ($data as $k => $v) {
            $values['general.'.$k] = $v;
        }
        $this->settings->setMany($values, $this->staff($request));
        $this->audit($request, 'system.settings_updated', ['group' => 'general', 'keys' => array_keys($data)]);

        return new JsonResponse(['data' => $this->settings->publicGroup('general')]);
    }

    public function uploadLogo(LogoRequest $request): JsonResponse
    {
        $file = $request->file('logo');
        // Raster formats only (no SVG: it can carry scripts); stored under a random name.
        $path = $file->storeAs('branding', 'logo-'.bin2hex(random_bytes(8)).'.'.$file->extension(), 'public');
        $old = $this->settings->string('general.logo_path');
        $this->settings->set('general.logo_path', $path, $this->staff($request));
        if ($old !== null && str_starts_with($old, 'branding/')) {
            Storage::disk('public')->delete($old);
        }
        $this->audit($request, 'system.settings_updated', ['group' => 'general', 'keys' => ['logo_path']]);

        return new JsonResponse(['data' => ['logo_path' => $path]]);
    }

    public function updateMail(MailSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        if ($data['mailer'] === 'env') {
            // Back to .env configuration: clear every saved SMTP value.
            foreach (['mailer', 'host', 'port', 'username', 'password', 'encryption', 'from_address', 'from_name', 'last_test'] as $k) {
                $this->settings->set('mail.'.$k, null, $this->staff($request));
            }
        } else {
            $values = ['mail.mailer' => 'smtp', 'mail.last_test' => null];
            foreach (['host', 'port', 'username', 'encryption', 'from_address', 'from_name'] as $k) {
                $values['mail.'.$k] = $data[$k] ?? null;
            }
            if (array_key_exists('password', $data) && $data['password'] !== null && $data['password'] !== '') {
                $values['mail.password'] = $data['password']; // omitted = keep the saved one
            }
            $this->settings->setMany($values, $this->staff($request));
        }
        $this->audit($request, 'system.settings_updated', ['group' => 'mail', 'keys' => array_keys(array_diff_key($data, ['password' => 1])), 'password_changed' => ! empty($data['password'])]);

        return new JsonResponse(['data' => $this->settings->publicGroup('mail')]);
    }

    public function testMail(TestEmailRequest $request): JsonResponse
    {
        $this->settings->set('mail.last_test', null, $this->staff($request));
        SendTestEmail::dispatch((string) $request->validated('to'), (string) $this->staff($request));

        return new JsonResponse(['data' => ['queued' => true]], 202);
    }

    public function updateGoogle(GoogleSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (($data['clear'] ?? false) === true) {
            $this->settings->setMany(['google.client_id' => null, 'google.client_secret' => null, 'google.last_test' => null], $this->staff($request));
        } else {
            $values = ['google.ga4_enabled' => (bool) ($data['ga4_enabled'] ?? false)];
            if (array_key_exists('client_id', $data) && $data['client_id'] !== null) {
                $values['google.client_id'] = $data['client_id'];
                $values['google.last_test'] = null;
            }
            if (($data['client_secret'] ?? null) !== null && $data['client_secret'] !== '') {
                $values['google.client_secret'] = $data['client_secret'];
                $values['google.last_test'] = null;
            }
            $this->settings->setMany($values, $this->staff($request));
        }
        $this->audit($request, 'system.settings_updated', ['group' => 'google', 'client_id_changed' => isset($data['client_id']), 'secret_changed' => ! empty($data['client_secret']), 'cleared' => (bool) ($data['clear'] ?? false)]);

        return new JsonResponse(['data' => ['saved' => true]]);
    }

    public function testGoogle(Request $request): JsonResponse
    {
        $this->settings->set('google.last_test', null, $this->staff($request));
        TestGoogleOAuth::dispatch((string) $this->staff($request));

        return new JsonResponse(['data' => ['queued' => true]], 202);
    }

    public function updateAlerts(AlertSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $values = ['alerts.emails' => array_values(array_unique(array_map('strtolower', $data['emails']))), 'alerts.cooldown_minutes' => $data['cooldown_minutes'] ?? null];
        if (($data['clear_telegram'] ?? false) === true) {
            $values['alerts.telegram_bot_token'] = null;
            $values['alerts.telegram_chat_id'] = null;
        } else {
            if (($data['telegram_bot_token'] ?? null) !== null) {
                $values['alerts.telegram_bot_token'] = $data['telegram_bot_token'];
            }
            if (array_key_exists('telegram_chat_id', $data)) {
                $values['alerts.telegram_chat_id'] = $data['telegram_chat_id'];
            }
        }
        $this->settings->setMany($values, $this->staff($request));
        $this->audit($request, 'system.settings_updated', ['group' => 'alerts', 'emails' => count($values['alerts.emails']), 'telegram_changed' => isset($data['telegram_bot_token']) || ($data['clear_telegram'] ?? false)]);

        return new JsonResponse(['data' => $this->settings->publicGroup('alerts')]);
    }

    public function testAlert(Request $request): JsonResponse
    {
        SendSystemAlert::dispatch('test', 'test', 'Thông báo thử nghiệm từ trang quản trị SiteX Post.', true);

        return new JsonResponse(['data' => ['queued' => true]], 202);
    }

    public function updateBackup(BackupSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->settings->setMany(['backup.schedule_enabled' => (bool) $data['schedule_enabled'], 'backup.schedule_time' => $data['schedule_time']], $this->staff($request));
        $this->audit($request, 'system.settings_updated', ['group' => 'backup'] + $data);

        return new JsonResponse(['data' => $this->settings->publicGroup('backup')]);
    }

    public function draftPlans(Request $request, BillingReadiness $billing): JsonResponse
    {
        $created = $billing->createDraftPlans(SetupWizard::STANDARD_PLANS, (string) $this->staff($request));

        return new JsonResponse(['data' => ['created' => $created]], $created === [] ? 200 : 201);
    }

    public function acknowledge(AcknowledgeRequest $request): JsonResponse
    {
        $ack = array_values(array_unique(array_merge((array) $this->settings->get('setup.acknowledged'), [(string) $request->validated('step')])));
        $this->settings->set('setup.acknowledged', $ack, $this->staff($request));

        return new JsonResponse(['data' => ['acknowledged' => $ack]]);
    }

    public function complete(Request $request, SetupWizard $wizard): JsonResponse
    {
        $overview = $wizard->overview();
        if ($overview['readiness'] === 'NOT_READY') {
            $final = collect($overview['steps'])->firstWhere('key', 'final');
            throw new ApiException(422, 'setup_not_ready', __('system.setup.not_ready'), ['blocking' => $final['data']['blocking'] ?? []]);
        }
        $this->settings->set('setup.completed_at', now()->toIso8601ZuluString(), $this->staff($request));
        $this->audit($request, 'system.setup_completed', ['readiness' => $overview['readiness']]);

        return new JsonResponse(['data' => $wizard->overview()]);
    }

    public function plugin(PluginPackage $package): BinaryFileResponse
    {
        $file = $package->latestZip();
        if ($file === null) {
            throw ApiException::notFound();
        }

        return response()->download($file, basename($file), ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store']);
    }

    private function staff(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function audit(Request $request, string $action, array $changes): void
    {
        Audit::record($action, null, $changes, null, $this->staff($request), 'staff');
    }
}
