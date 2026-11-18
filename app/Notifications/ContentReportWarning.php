<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use App\Support\ModerationNoticeLabels;
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
        $locale = $this->resolveLocale($notifiable);

        $mail = (new MailMessage)
            ->subject(__('notifications.subject_content_warning', [], $locale))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_content_warning', [
                'entityType' => ModerationNoticeLabels::entityType($this->entityType, $locale),
                'entityName' => $this->entityName,
            ], $locale))
            ->line(__('notifications.body_reason', [
                'reason' => ModerationNoticeLabels::reason($this->reason, $locale),
            ], $locale))
            ->line(__('notifications.body_content_warning_guidelines', [], $locale));

        return $this->withPreheader($mail, 'notifications.preheader_content_report_warning');
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
        return null; // System/admin action, no specific actor
    }
}
