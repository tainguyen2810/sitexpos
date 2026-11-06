<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use SiteXPost\Kernel\Logging\SecretRedactor;
use SiteXPost\Modules\System\Support\RuntimeConfig;
use SiteXPost\Modules\System\Support\SystemSettings;
use Throwable;

/**
 * Setup Wizard "Send test email": result stored in mail.last_test (never the password).
 */
final class SendTestEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $to, public readonly string $userId)
    {
        $this->onQueue('notifications');
    }

    public function handle(SystemSettings $settings, RuntimeConfig $runtime): void
    {
        $result = ['ok' => false, 'to' => $this->to, 'at' => now()->toIso8601ZuluString(), 'mailer' => null, 'error' => null];
        try {
            $runtime->applyMail();
            $result['mailer'] = (string) config('mail.default');
            Mail::raw(__('system.mail_test_body'), function ($m): void {
                $m->to($this->to)->subject(__('system.mail_test_subject'));
            });
            $result['ok'] = true;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $pw = $settings->secret('mail.password');
            if ($pw !== null && $pw !== '') {
                $msg = str_replace($pw, '[REDACTED]', $msg);
            }
            $result['error'] = SecretRedactor::redact($msg, 300);
        }
        $settings->set('mail.last_test', $result, $this->userId);
    }
}
