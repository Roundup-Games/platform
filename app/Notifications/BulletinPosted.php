<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class BulletinPosted extends BaseNotification
{
    /**
     * @param  Game  $game  The game the bulletin was posted to
     * @param  User  $host  The host who posted the bulletin
     * @param  GameBulletin  $bulletin  The bulletin that was posted
     */
    public function __construct(
        public Game $game,
        public User $host,
        public GameBulletin $bulletin,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_bulletin_posted', [
                'game' => $this->game->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_bulletin_posted', [
                'host' => $this->host->name,
                'game' => $this->game->name,
            ]))
            ->line(__('notifications.body_bulletin_content', [
                'content' => Str::limit($this->bulletin->content, 100),
            ]))
            ->action(__('notifications.action_view_game'), route('games.show', ['locale' => $locale, 'id' => $this->game->id]));
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
            'type' => 'bulletin_posted',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'actor_id' => $this->host->id,
            'bulletin_id' => $this->bulletin->id,
            'action_url' => route('games.show', ['locale' => $locale, 'id' => $this->game->id]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): ?User
    {
        return $this->host;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(object $notifiable): ?PushPayload
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.push_title_bulletin_posted'),
            body: __('notifications.push_body_bulletin_posted', [
                'host' => $this->host->name,
                'game' => $this->game->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('games.show', ['locale' => $locale, 'id' => $this->game->id]),
            tag: "bulletin-{$this->bulletin->id}",
        );
    }
}
