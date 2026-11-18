<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use App\Support\ModerationNoticeLabels;
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
        $locale = $this->resolveLocale($notifiable);

        $mail = (new MailMessage)
            ->subject(__('notifications.subject_account_suspended', [], $locale))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_account_suspended', [], $locale))
            ->line(__('notifications.body_reason', [
                'reason' => ModerationNoticeLabels::reason($this->reason, $locale),
            ], $locale))
            ->line(__('notifications.body_account_suspended_contact', [], $locale));

        return $this->withPreheader($mail, 'notifications.preheader_account_suspended');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => 'account_suspended',
            'reason' => ModerationNoticeLabels::reason($this->reason, $this->resolveLocale($notifiable)),
        ];
    }

    /**
     * Discord opted out (D130): this type has no push surface, so it has no Discord surface either.
     */
    public function toDiscord(User $notifiable): ?DiscordWebhookPayload
    {
        return null;
    }

    public function getActor(): ?User
    {
        return null; // System/admin action
    }
}
