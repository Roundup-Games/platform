<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Notification sent to a user when their account has been suspended by admin moderation.
 * Triggered by admin "Suspend User" action on a content report ticket.
 */
class AccountSuspended extends BaseNotification
{
    public function __construct(
        public string $reason,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.subject_account_suspended'))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_account_suspended'))
            ->line(__('notifications.body_account_suspended_reason', [
                'reason' => $this->reason,
            ]))
            ->line(__('notifications.body_account_suspended_contact'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => 'account_suspended',
            'reason' => $this->reason,
        ];
    }

    public function getActor(): ?User
    {
        return null; // System/admin action
    }
}
