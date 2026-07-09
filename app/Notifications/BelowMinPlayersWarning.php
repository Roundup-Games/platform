<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class BelowMinPlayersWarning extends BaseNotification
{
    use HasUnsubscribeLink;

    public function __construct(
        public Game $game,
        public int $currentCount,
        public int $minPlayers,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

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
            ->action(__('notifications.action_view_game', ['game' => $this->game->name]), route('games.show', ['locale' => $locale, 'id' => $this->game]))
            ->line($this->unsubscribeLine($notifiable, 'below_min_players'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

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
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.push_title_below_min_players'),
            body: __('notifications.push_body_below_min_players', [
                'game' => $this->game->name,
                'current' => $this->currentCount,
                'min' => $this->minPlayers,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('games.show', ['locale' => $locale, 'id' => $this->game]),
            tag: "below-min-players-{$this->game->id}",
        );
    }
}
