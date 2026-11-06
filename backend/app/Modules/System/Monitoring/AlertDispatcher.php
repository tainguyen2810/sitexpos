<?php

declare(strict_types=1);

namespace SiteXPost\Modules\System\Monitoring;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use SiteXPost\Kernel\Logging\SecretRedactor;
use SiteXPost\Modules\System\Domain\SystemAlert;
use SiteXPost\Modules\System\Support\RuntimeConfig;
use SiteXPost\Modules\System\Support\SystemSettings;
use Throwable;

/**
 * Sends an operational alert by email (configured recipients, or every
 * SUPER_ADMIN) and Telegram (when a bot token + chat id are saved). A
 * cooldown per check + severity prevents alert storms. Called from queued
 * jobs only (outbound network).
 */
final class AlertDispatcher
{
    public function __construct(private readonly SystemSettings $settings, private readonly RuntimeConfig $runtime) {}

    public function inCooldown(string $checkKey, string $severity): bool
    {
        $minutes = (int) ($this->settings->get('alerts.cooldown_minutes') ?? config('sitex.system.alert_cooldown_minutes', 60));

        return SystemAlert::query()->where('check_key', $checkKey)->where('severity', $severity)
            ->where('created_at', '>=', now()->subMinutes(max(1, $minutes)))->exists();
    }

    /**
     * @return array<string, string> channel => sent|failed|skipped
     */
    public function send(string $checkKey, string $severity, string $summary, bool $force = false): array
    {
        if (! $force && $this->inCooldown($checkKey, $severity)) {
            return ['cooldown' => 'skipped'];
        }

        $channels = ['mail' => $this->mail($checkKey, $severity, $summary), 'telegram' => $this->telegram($checkKey, $severity, $summary)];

        $alert = new SystemAlert;
        $alert->forceFill([
            'check_key' => $checkKey,
            'severity' => $severity,
            'summary' => mb_substr($summary, 0, 500),
            'channels' => $channels,
            'resolved_at' => $severity === 'recovered' ? now() : null,
        ])->save();

        return $channels;
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        $configured = array_values(array_filter((array) $this->settings->get('alerts.emails'), fn ($e) => is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL)));
        if ($configured !== []) {
            return $configured;
        }

        return DB::table('users')->where('platform_role', 'SUPER_ADMIN')->where('status', 'active')->pluck('email')->map(fn ($e) => (string) $e)->all();
    }

    private function mail(string $checkKey, string $severity, string $summary): string
    {
        $to = $this->recipients();
        if ($to === []) {
            return 'skipped';
        }
        try {
            $this->runtime->applyMail();
            $subject = __('system.alert_subject', ['severity' => strtoupper($severity), 'check' => $checkKey]);
            $body = $summary."\n\n".rtrim((string) config('sitex.admin_url'), '/').'/system/health';
            Mail::raw($body, function ($m) use ($to, $subject): void {
                $m->to($to)->subject($subject);
            });

            return 'sent';
        } catch (Throwable) {
            return 'failed';
        }
    }

    private function telegram(string $checkKey, string $severity, string $summary): string
    {
        $token = $this->settings->secret('alerts.telegram_bot_token');
        $chat = $this->settings->string('alerts.telegram_chat_id');
        if ($token === null || $chat === null) {
            return 'skipped';
        }
        try {
            $icon = ['critical' => '🔴', 'warning' => '🟠', 'recovered' => '🟢', 'test' => '🔵'][$severity] ?? '•';
            $response = Http::timeout(10)->connectTimeout(5)->asForm()
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $chat,
                    'text' => $icon.' SiteX Post — '.$checkKey."\n".$summary,
                    'disable_web_page_preview' => 'true',
                ]);

            return $response->successful() ? 'sent' : 'failed';
        } catch (Throwable $e) {
            report(new \RuntimeException('telegram alert failed: '.SecretRedactor::redact(str_replace($token, '[REDACTED]', $e->getMessage()))));

            return 'failed';
        }
    }
}
