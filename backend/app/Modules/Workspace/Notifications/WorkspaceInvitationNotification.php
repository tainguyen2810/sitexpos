<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workspace\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SiteXPost\Modules\Workspace\Domain\WorkspaceInvitation;

final class WorkspaceInvitationNotification extends Notification
{
    public function __construct(
        public readonly WorkspaceInvitation $invitation,
        #[\SensitiveParameter] private readonly string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('sitex.frontend_url'), '/').'/invitations/'.$this->token;
        $workspace = (string) $this->invitation->workspace?->name;
        $inviter = (string) $this->invitation->inviter?->name;

        return (new MailMessage)
            ->subject(__('mail.invitation.subject', ['workspace' => $workspace]))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.invitation.line', ['inviter' => $inviter, 'workspace' => $workspace, 'role' => $this->invitation->role->value]))
            ->action(__('mail.invitation.action'), $url)
            ->line(__('mail.invitation.expires', ['date' => $this->invitation->expires_at->format('d/m/Y')]))
            ->line(__('mail.invitation.ignore'));
    }
}
