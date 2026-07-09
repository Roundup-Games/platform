<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class PlayerBenched extends BaseNotification
{
    use HasUnsubscribeLink;

    /**
     * @param  Game|Campaign  $entity  The Campaign or campaign-session Game
     * @param  string  $entityType  'campaign' or 'game'
     */
    public function __construct(
        public Game|Campaign $entity,
        public string $entityType,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $actionUrl = $this->resolveEntityUrl($locale);
        $entityTypeLabel = $this->entityTypeLabel();

        return (new MailMessage)
            ->subject(__('notifications.subject_player_benched', [
                'entity' => $this->entity->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_player_benched', [
                'entity' => $this->entity->name,
            ]))
            ->action(__('notifications.action_player_benched', ['entity_type' => ucfirst($entityTypeLabel)]), $actionUrl)
            ->line($this->unsubscribeLine($notifiable, 'player_benched'));
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
            'type' => 'player_benched',
            'entity_type' => $this->entityType,
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'action_url' => $this->resolveEntityUrl($locale),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns the entity owner as the closest actor.
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

        return new PushPayload(
            title: __('notifications.push_title_player_benched'),
            body: __('notifications.push_body_player_benched', [
                'entity' => $this->entity->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: $this->resolveEntityUrl($locale),
            tag: "player-benched-{$this->entityType}-{$this->entity->id}",
        );
    }

    /**
     * Resolve the entity detail URL from the entity type and ID.
     */
    protected function resolveEntityUrl(string $locale): string
    {
        return match ($this->entityType) {
            'campaign' => route('campaigns.show', ['locale' => $locale, 'id' => $this->entity]),
            default => route('games.show', ['locale' => $locale, 'id' => $this->entity]),
        };
    }

    /**
     * Get a human-readable entity type label for the current locale.
     */
    protected function entityTypeLabel(): string
    {
        return match ($this->entityType) {
            'campaign' => __('notifications.entity_type_campaign'),
            default => __('notifications.entity_type_game'),
        };
    }
}
