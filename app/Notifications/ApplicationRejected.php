<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class ApplicationRejected extends BaseNotification
{
    use HasUnsubscribeLink;

    /**
     * @param  Game|Campaign  $entity  The Game or Campaign entity
     * @param  string  $entityType  'game' or 'campaign'
     * @param  User  $rejector  The user who rejected the application
     */
    public function __construct(
        public Game|Campaign $entity,
        public string $entityType,
        public User $rejector,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_application_rejected', [
                'entity' => $this->entity->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_application_rejected', [
                'entity' => $this->entity->name,
            ]))
            ->action(__('notifications.action_application_rejected'), route('games.index', ['locale' => $locale]))
            ->line($this->unsubscribeLine($notifiable, 'application_rejected'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return [
            'type' => 'application_rejected',
            'entity_type' => $this->entityType,
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'rejector_id' => $this->rejector->id,
            'rejector_name' => $this->rejector->name,
            'action_url' => route('games.index', ['locale' => $locale]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): User
    {
        return $this->rejector;
    }

    /**
     * Get the push notification representation.
     * Not applicable for this notification type.
     */
    public function toPush(User $notifiable): ?PushPayload
    {
        return null;
    }
}
