<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GameCancelled extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  Game  $game  The game that was cancelled
     */
    public function __construct(
        public Game $game,
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
        $date = $this->game->date_time?->format('M j, Y') ?? '';

        return (new MailMessage)
            ->subject(__('notifications.subject_game_cancelled', [
                'game' => $this->game->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_game_cancelled', [
                'game' => $this->game->name,
                'date' => $date,
            ]))
            ->action(__('notifications.action_game_cancelled'), route('games.index'))
            ->line($this->unsubscribeLine($notifiable, 'game_cancelled'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'game_cancelled',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'date_time' => $this->game->date_time?->toIso8601String(),
            'action_url' => route('games.index'),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns the game owner as the closest actor for status changes.
     */
    public function getActor(): ?User
    {
        return $this->game->owner;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(object $notifiable): PushPayload
    {
        return new PushPayload(
            title: __('notifications.push_title_game_cancelled'),
            body: __('notifications.push_body_game_cancelled', [
                'game' => $this->game->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('games.detail', ['locale' => app()->getLocale(), 'id' => $this->game->id]),
            tag: "game-cancelled-{$this->game->id}",
        );
    }
}
