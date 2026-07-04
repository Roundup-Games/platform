<?php

namespace App\Notifications;

use App\Models\User;
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
        // Scoped removals (cover image) use a subject/body that names the
        // specific artifact instead of claiming the whole entity was removed.
        if ($this->scope === 'cover_image') {
            return (new MailMessage)
                ->subject(__('notifications.subject_cover_removed'))
                ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
                ->line(__('notifications.body_cover_removed', [
                    'entityType' => $this->entityType,
                    'entityName' => $this->entityName,
                ]))
                ->line(__('notifications.body_content_removed_reason', [
                    'reason' => $this->reason,
                ]))
                ->line(__('notifications.body_content_removed_guidelines'));
        }

        return (new MailMessage)
            ->subject(__('notifications.subject_content_removed'))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_content_removed', [
                'entityType' => $this->entityType,
                'entityName' => $this->entityName,
            ]))
            ->line(__('notifications.body_content_removed_reason', [
                'reason' => $this->reason,
            ]))
            ->line(__('notifications.body_content_removed_guidelines'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => $this->scope === 'cover_image' ? 'cover_image_removed' : 'content_removed',
            'entity_type' => $this->entityType,
            'entity_name' => $this->entityName,
            'reason' => $this->reason,
            'scope' => $this->scope,
        ];
    }

    public function getActor(): ?User
    {
        return null; // System/admin action
    }
}
