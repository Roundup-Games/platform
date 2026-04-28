<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BelowMinPlayersWarning extends Notification
{
    use HasUnsubscribeLink;

    public function __construct(
        public Game $game,
        public int $currentCount,
        public int $minPlayers,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [DatabaseChannel::class, MailChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_below_min_players', [
                'game' => $this->game->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_below_min_players', [
                'game' => $this->game->name,
                'current' => $this->currentCount,
                'min' => $this->minPlayers,
            ]))
            ->action(__('notifications.action_view_game', ['game' => $this->game->name]), route('games.detail', ['locale' => $locale, 'id' => $this->game->id]))
            ->line($this->unsubscribeLine($notifiable, 'below_min_players'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return [
            'type' => 'below_min_players',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'current_count' => $this->currentCount,
            'min_players' => $this->minPlayers,
            'action_url' => route('games.detail', ['locale' => $locale, 'id' => $this->game->id]),
        ];
    }

    public function getActor(): ?User
    {
        return $this->game->owner;
    }

    public function toPush(object $notifiable): ?PushPayload
    {
        return null;
    }
}
