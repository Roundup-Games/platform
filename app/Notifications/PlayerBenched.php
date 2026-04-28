<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlayerBenched extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $entity  The Campaign or campaign-session Game
     * @param  string  $entityType  'campaign' or 'game'
     */
    public function __construct(
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
    public function toDatabase(object $notifiable): array
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

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
    public function toPush(object $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

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
            'campaign' => route('campaigns.detail', ['locale' => $locale, 'id' => $this->entity->id]),
            default => route('games.detail', ['locale' => $locale, 'id' => $this->entity->id]),
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
