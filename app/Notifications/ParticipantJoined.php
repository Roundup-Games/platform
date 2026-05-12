<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ParticipantJoined extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  User  $participant  The user who joined
     * @param  \Illuminate\Database\Eloquent\Model  $entity  The Game or Campaign entity
     * @param  string  $entityType  'game' or 'campaign'
     */
    public function __construct(
        public User $participant,
        public $entity,
        public string $entityType,
    ) {}

    /**
     * Get the notification's delivery channels.
     * When dispatched via NotificationService, channels are resolved
     * from user preferences; this serves as a fallback default.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [DatabaseChannel::class, MailChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();
        $actionUrl = $this->resolveEntityUrl($locale);
        $entityTypeLabel = $this->entityTypeLabel();

        return (new MailMessage)
            ->subject(__('notifications.subject_participant_joined', [
                'participant' => $this->participant->name,
                'entity' => $this->entity->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_participant_joined', [
                'participant' => $this->participant->name,
                'entity_type' => $entityTypeLabel,
                'entity' => $this->entity->name,
            ]))
            ->action(__('notifications.action_participant_joined', ['entity_type' => $entityTypeLabel]), $actionUrl)
            ->line($this->unsubscribeLine($notifiable, 'participant_joined'));
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
            'type' => 'participant_joined',
            'participant_id' => $this->participant->id,
            'participant_name' => $this->participant->name,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'action_url' => $this->resolveEntityUrl($locale),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): User
    {
        return $this->participant;
    }

    /**
     * Resolve the entity detail URL from the entity type and ID.
     */
    protected function resolveEntityUrl(string $locale): string
    {
        return match ($this->entityType) {
            'campaign' => route('campaigns.show', ['locale' => $locale, 'id' => $this->entity->id]),
            default => route('games.show', ['locale' => $locale, 'id' => $this->entity->id]),
        };
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
