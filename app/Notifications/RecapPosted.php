<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class RecapPosted extends BaseNotification
{
    use HasUnsubscribeLink;

    /**
     * @param  Game  $game  The game the recap was written for
     * @param  User  $author  The host who wrote the recap
     */
    public function __construct(
        public Game $game,
        public User $author,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_recap_posted', [
                'game' => $this->game->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_recap_posted', [
                'host' => $this->author->name,
                'game' => $this->game->name,
            ]))
            ->action(__('notifications.action_view_recap'), route('games.show', ['locale' => $locale, 'id' => $this->game->id]))
            ->line($this->unsubscribeLine($notifiable, 'recap_posted'));
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
            'type' => 'recap_posted',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'actor_id' => $this->author->id,
            'action_url' => route('games.show', ['locale' => $locale, 'id' => $this->game->id]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): ?User
    {
        return $this->author;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(object $notifiable): ?PushPayload
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.push_title_recap_posted'),
            body: __('notifications.push_body_recap_posted', [
                'host' => $this->author->name,
                'game' => $this->game->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('games.show', ['locale' => $locale, 'id' => $this->game->id]),
            tag: "recap-{$this->game->id}",
        );
    }
}
