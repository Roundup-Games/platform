<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class EntityCancelled extends BaseNotification
{
    use HasUnsubscribeLink;
    use RoutesGameOrCampaign;

    /**
     * @param  Game|Campaign  $entity  The game or campaign that was cancelled
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

        $mail = (new MailMessage)
            ->subject(__("notifications.subject_{$type}_cancelled", [
                $type => $this->entity->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]));

        // GameCancelled includes date in body; CampaignCancelled does not
        if ($type === 'game') {
            $date = $this->entity->date_time?->format('M j, Y') ?? '';
            $mail->line(__('notifications.body_game_cancelled', [
                'game' => $this->entity->name,
                'date' => $date,
            ]));
        } else {
            $mail->line(__('notifications.body_campaign_cancelled', [
                'campaign' => $this->entity->name,
            ]));
        }

        $mail->action(__("notifications.action_{$type}_cancelled"), route("{$type}s.index", ['locale' => $locale]))
            ->line($this->unsubscribeLine($notifiable, "{$type}_cancelled"));

        return $mail;
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

        $data = [
            'type' => "{$type}_cancelled",
            'entity_type' => $type,
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'action_url' => route("{$type}s.index", ['locale' => $locale]),
        ];

        // GameCancelled includes date_time; CampaignCancelled does not
        if ($type === 'game') {
            $data['date_time'] = $this->entity->date_time?->toIso8601String();
        }

        return $data;
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
     */
    public function toPush(User $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $type = $this->getEntityType();

        return new PushPayload(
            title: __("notifications.push_title_{$type}_cancelled"),
            body: __("notifications.push_body_{$type}_cancelled", [
                $type => $this->entity->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity]),
            tag: "{$type}-cancelled-{$this->entity->id}",
        );
    }
}
