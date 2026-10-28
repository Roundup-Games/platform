<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class BelowMinPlayersWarning extends BaseNotification
{
    public function __construct(
        public Game $game,
        public int $currentCount,
        public int $minPlayers,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);

        return (new MailMessage)
            ->subject(__('notifications.subject_below_min_players', [
                'game' => $this->game->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_below_min_players', [
                'game' => $this->game->name,
                'current' => $this->currentCount,
                'min' => $this->minPlayers,
            ]))
            ->action(__('notifications.action_view_game', ['game' => $this->game->name]), route('games.show', ['locale' => $locale, 'id' => $this->game]))
            ->line($this->unsubscribeLine($notifiable, 'below_min_players'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'below_min_players',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'current_count' => $this->currentCount,
            'min_players' => $this->minPlayers,
            'action_url' => route('games.show', ['locale' => $locale, 'id' => $this->game]),
        ];
    }

    public function getActor(): ?User
    {
        return $this->game->owner;
    }

    public function toPush(User $notifiable): PushPayload
    {
        return $this->pushPayload(
            __('notifications.push_title_below_min_players'),
            __('notifications.push_body_below_min_players', [
                'game' => $this->game->name,
                'current' => $this->currentCount,
                'min' => $this->minPlayers,
            ]),
            route('games.show', ['locale' => $this->resolveLocale($notifiable), 'id' => $this->game]),
            "below-min-players-{$this->game->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        return $this->discordEmbed(
            __('notifications.push_title_below_min_players'),
            route('games.show', ['locale' => $this->resolveLocale($notifiable), 'id' => $this->game]),
            __('notifications.push_body_below_min_players', [
                'game' => $this->game->name,
                'current' => $this->currentCount,
                'min' => $this->minPlayers,
            ]),
        );
    }
}
