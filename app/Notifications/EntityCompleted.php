<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class EntityCompleted extends BaseNotification
{
    use HasUnsubscribeLink;
    use RoutesGameOrCampaign;

    /**
     * @param  Game|Campaign  $entity  The game or campaign that was completed
     */
    public function __construct(
        public Game|Campaign $entity,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $type = $this->getEntityType();

        return (new MailMessage)
            ->subject(__("notifications.subject_{$type}_completed", [
                $type => $this->entity->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__("notifications.body_{$type}_completed", [
                $type => $this->entity->name,
            ]))
            ->action(__("notifications.action_{$type}_completed"), route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity]))
            ->line($this->unsubscribeLine($notifiable, "{$type}_completed"));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $type = $this->getEntityType();

        return [
            'type' => "{$type}_completed",
            'entity_type' => $type,
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'action_url' => route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns the entity owner as the closest actor for status changes.
     */
    public function getActor(): ?User
    {
        return $this->entity->owner;
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
