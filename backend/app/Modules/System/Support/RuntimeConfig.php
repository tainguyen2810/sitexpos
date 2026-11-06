<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Support;

use Illuminate\Mail\MailManager;
use Throwable;

/**
 * Applies settings saved in the Setup Wizard on top of .env: SMTP (mail.*)
 * and Google OAuth (services.google.*). Values entered in the UI win over
 * .env; when nothing is saved, .env stays in charge. Fails silently when the
 * database is not ready (installer, first boot).
 */
final class RuntimeConfig
{
    public function __construct(private readonly SystemSettings $settings) {}

    public function apply(): void
    {
        try {
            $this->applyMail();
            $this->applyGoogle();
        } catch (Throwable) {
            // settings unavailable — keep .env configuration
        }
    }

    public function applyMail(): void
    {
        if ($this->settings->string('mail.mailer') !== 'smtp') {
            return;
        }
        $encryption = $this->settings->string('mail.encryption');
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.host' => $this->settings->string('mail.host'),
            'mail.mailers.smtp.port' => (int) ($this->settings->get('mail.port') ?? 587),
            'mail.mailers.smtp.username' => $this->settings->string('mail.username'),
            'mail.mailers.smtp.password' => $this->settings->secret('mail.password'),
            'mail.mailers.smtp.timeout' => 15,
            'mail.from.address' => $this->settings->string('mail.from_address') ?? config('mail.from.address'),
            'mail.from.name' => $this->settings->string('mail.from_name') ?? config('mail.from.name'),
        ]);
        if (app()->resolved('mail.manager')) {
            app(MailManager::class)->purge('smtp');
        }
    }

    public function applyGoogle(): void
    {
        $id = $this->settings->string('google.client_id');
        $secret = $this->settings->secret('google.client_secret');
        if ($id !== null && $secret !== null) {
            config(['services.google.client_id' => $id, 'services.google.client_secret' => $secret]);
        }
    }
}
