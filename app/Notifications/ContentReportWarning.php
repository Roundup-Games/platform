<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Notification sent to a user when they receive a warning about community guidelines.
 * Triggered by admin "Warn" action on a content report ticket.
 */
class ContentReportWarning extends BaseNotification
{
    public function __construct(
        public string $entityType,
        public string $entityName,
        public string $reason,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.subject_content_warning'))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_content_warning', [
                'entityType' => $this->entityType,
                'entityName' => $this->entityName,
            ]))
            ->line(__('notifications.body_content_warning_reason', [
                'reason' => $this->reason,
            ]))
            ->line(__('notifications.body_content_warning_guidelines'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => 'content_report_warning',
            'entity_type' => $this->entityType,
            'entity_name' => $this->entityName,
            'reason' => $this->reason,
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
        return null; // System/admin action, no specific actor
    }
}
