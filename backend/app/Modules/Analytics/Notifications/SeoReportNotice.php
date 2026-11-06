<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Analytics\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * SEO report e-mail: a summary and a link into the app (the full report
 * stays behind authentication — no report data in the link).
 */
final class SeoReportNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $workspaceId, public readonly string $reportId, public readonly string $workspaceName, public readonly string $period,
        public readonly ?int $clicks, public readonly ?float $clicksChange)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('sitex.frontend_url'), '/').'/w/'.$this->workspaceId.'/analytics/reports/'.$this->reportId;
        $m = (new MailMessage)->subject(__('analytics.mail.subject', ['workspace' => $this->workspaceName, 'period' => $this->period]))->greeting(__('mail.greeting'));
        $m->line($this->clicks === null ? __('analytics.mail.no_data') : __('analytics.mail.summary', ['clicks' => $this->clicks, 'change' => $this->clicksChange === null ? '—' : $this->clicksChange.'%']));

        return $m->line(__('analytics.mail.note'))->action(__('analytics.mail.action'), $url);
    }
}
