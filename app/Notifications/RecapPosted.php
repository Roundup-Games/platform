<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class RecapPosted extends BaseNotification
{
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
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);

        return (new MailMessage)
            ->subject(__('notifications.subject_recap_posted', [
                'host' => $this->author->name,
                'game' => $this->game->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_recap_posted', [
                'host' => $this->author->name,
                'game' => $this->game->name,
            ]))
            ->action(__('notifications.action_view_recap'), route('games.show', ['locale' => $locale, 'id' => $this->game]))
            ->line($this->unsubscribeLine($notifiable, 'recap_posted'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'recap_posted',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'actor_id' => $this->author->id,
            'action_url' => route('games.show', ['locale' => $locale, 'id' => $this->game]),
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
    public function toPush(User $notifiable): ?PushPayload
    {
        return $this->pushPayload(
            __('notifications.push_title_recap_posted'),
            __('notifications.subject_recap_posted', [
                'host' => $this->author->name,
                'game' => $this->game->name,
            ]),
            route('games.show', ['locale' => $this->resolveLocale($notifiable), 'id' => $this->game]),
            "recap-{$this->game->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        return $this->discordEmbed(
            __('notifications.push_title_recap_posted'),
            route('games.show', ['locale' => $this->resolveLocale($notifiable), 'id' => $this->game]),
            __('notifications.subject_recap_posted', [
                'host' => $this->author->name,
                'game' => $this->game->name,
            ]),
        );
    }
}
