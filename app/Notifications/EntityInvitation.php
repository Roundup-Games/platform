<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class EntityInvitation extends BaseNotification
{
    use HasUnsubscribeLink;
    use RoutesGameOrCampaign;

    /**
     * @param  Game|Campaign  $entity  The game or campaign the user is invited to
     * @param  User  $inviter  The user who sent the invitation
     */
    public function __construct(
        public Game|Campaign $entity,
        public User $inviter,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();
        $type = $this->getEntityType();
        $actionUrl = route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity->id]);

        return (new MailMessage)
            ->subject(__("notifications.subject_{$type}_invitation", [
                'inviter' => $this->inviter->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__("notifications.body_{$type}_invitation", [
                'inviter' => $this->inviter->name,
                $type => $this->entity->name,
            ]))
            ->action(__("notifications.action_{$type}_invitation"), $actionUrl)
            ->line($this->unsubscribeLine($notifiable, "{$type}_invitation"));
    }

    /**
     * Get the array representation of the notification.
     *
     * Preserves backward-compatible keys: {entity_type}_id / {entity_type}_name
     * for existing database records and frontend code.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();
        $type = $this->getEntityType();

        return [
            'type' => "{$type}_invitation",
            "{$type}_id" => $this->entity->id,
            "{$type}_name" => $this->entity->name,
            'inviter_id' => $this->inviter->id,
            'inviter_name' => $this->inviter->name,
            'action_url' => route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity->id]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): User
    {
        return $this->inviter;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(object $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();
        $type = $this->getEntityType();

        return new PushPayload(
            title: __("notifications.push_title_{$type}_invitation"),
            body: __("notifications.push_body_{$type}_invitation", [
                'inviter' => $this->inviter->name,
                $type => $this->entity->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity->id]),
            tag: "{$type}-invitation-{$this->entity->id}",
        );
    }
}
