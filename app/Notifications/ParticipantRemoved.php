<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class ParticipantRemoved extends BaseNotification
{
    use HasUnsubscribeLink;

    /**
     * @param  User  $removedUser  The user who was removed
     * @param  \Illuminate\Database\Eloquent\Model  $entity  The Game or Campaign entity
     * @param  string  $entityType  'game' or 'campaign'
     */
    public function __construct(
        public User $removedUser,
        public $entity,
        public string $entityType,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();
        $entityTypeLabel = $this->entityTypeLabel();

        return (new MailMessage)
            ->subject(__('notifications.subject_participant_removed', [
                'entity' => $this->entity->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_participant_removed', [
                'entity' => $this->entity->name,
            ]))
            ->action(__('notifications.action_participant_removed'), route('games.index', ['locale' => $locale]))
            ->line($this->unsubscribeLine($notifiable, 'participant_removed'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return [
            'type' => 'participant_removed',
            'removed_user_id' => $this->removedUser->id,
            'removed_user_name' => $this->removedUser->name,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'action_url' => route('games.index', ['locale' => $locale]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * For removals, the actor is the entity owner — but we store the
     * removed user for context. The organizer who triggered the removal
     * isn't passed to this class; the service-level block-list check
     * is not applicable here (the recipient IS the removed user).
     */
    public function getActor(): ?User
    {
        return null;
    }

    /**
     * Get a human-readable entity type label for the current locale.
     */
    protected function entityTypeLabel(): string
    {
        return match ($this->entityType) {
            'campaign' => __('notifications.entity_type_campaign'),
            'team' => __('notifications.entity_type_team'),
            default => __('notifications.entity_type_game'),
        };
    }

    /**
     * Get the push notification representation.
     * Not applicable for this notification type.
     */
    public function toPush(object $notifiable): ?PushPayload
    {
        return null;
    }
}
