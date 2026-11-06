<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Billing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Billing e-mail (expiring soon, expired, payment received/failed, plan
 * changed, low credits). Sent once per dedupe key by BillingNotifier.
 */
final class BillingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly string $type, public readonly string $workspaceName, public readonly string $workspaceId, public readonly array $data = [])
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
        $url = rtrim((string) config('sitex.frontend_url'), '/').'/w/'.$this->workspaceId.'/billing';
        $params = ['workspace' => $this->workspaceName] + array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $this->data);

        return (new MailMessage)
            ->subject(__('billing.mail.'.$this->type.'.subject', $params))
            ->greeting(__('mail.greeting'))
            ->line(__('billing.mail.'.$this->type.'.line', $params))
            ->action(__('billing.mail.action'), $url);
    }
}
