<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use App\Support\ModerationNoticeLabels;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Notification sent to a user when their content has been removed by admin moderation.
 * Triggered by admin "Remove Content" action on a content report ticket.
 *
 * The optional $scope distinguishes a whole-entity removal (default — the
 * game/campaign itself was canceled) from a scoped removal (e.g. 'cover_image'
 * when only the host-uploaded cover was cleared via the cover-takedown action).
 * Backwards-compatible: callers that omit $scope get the legacy "removed"
 * wording, so the existing whole-content path is unchanged.
 */
class ContentRemoved extends BaseNotification
{
    public function __construct(
        public string $entityType,
        public string $entityName,
        public string $reason,
        public ?string $scope = null,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        $entityType = ModerationNoticeLabels::entityType($this->entityType, $locale);
        $reason = ModerationNoticeLabels::reason($this->reason, $locale);

        // Scoped removals (cover image) use a subject/body that names the
        // specific artifact instead of claiming the whole entity was removed.
        if ($this->scope === 'cover_image') {
            $mail = (new MailMessage)
                ->subject(__('notifications.subject_cover_removed', [], $locale))
                ->greeting($this->greetingLine($notifiable))
                ->line(__('notifications.body_cover_removed', [
                    'entityType' => $entityType,
                    'entityName' => $this->entityName,
                ], $locale))
                ->line(__('notifications.body_reason', [
                    'reason' => $reason,
                ], $locale))
                ->line(__('notifications.body_content_removed_guidelines', [], $locale));

            return $this->withPreheader($mail, 'notifications.preheader_cover_removed', [
                'entityType' => $entityType,
            ]);
        }

        $mail = (new MailMessage)
            ->subject(__('notifications.subject_content_removed', [], $locale))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_content_removed', [
                'entityType' => $entityType,
                'entityName' => $this->entityName,
            ], $locale))
            ->line(__('notifications.body_reason', [
                'reason' => $reason,
            ], $locale))
            ->line(__('notifications.body_content_removed_guidelines', [], $locale));

        return $this->withPreheader($mail, 'notifications.preheader_content_removed');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => $this->scope === 'cover_image' ? 'cover_image_removed' : 'content_removed',
            'entity_type' => $this->entityType,
            'entity_name' => $this->entityName,
            'reason' => ModerationNoticeLabels::reason($this->reason, $locale),
            'scope' => $this->scope,
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
