<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Push-only notification sent to game participants when their session
 * is starting within the next hour.
 *
 * Dispatched by the SendSessionReminders artisan command, not by user actions.
 * Intentionally has no mail or database representation — push-only by design.
 */
class SessionReminder extends Notification
{
    /**
     * @param  Game  $game  The game that is starting soon
     */
    public function __construct(
        public Game $game,
    ) {}

    /**
     * Push-only delivery. The command sends directly via PushChannel,
     * but this provides a fallback via() for Laravel's notification system.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [DatabaseChannel::class];
    }

    /**
     * Get the database representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'session_reminder',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'date_time' => $this->game->date_time?->toIso8601String(),
            'action_url' => route('games.detail', $this->game->id),
        ];
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(object $notifiable): PushPayload
    {
        $time = $this->game->date_time?->format('g:i A') ?? '';

        return new PushPayload(
            title: __('notifications.push_title_session_reminder'),
            body: __('notifications.push_body_session_reminder', [
                'game' => $this->game->name,
                'time' => $time,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('games.detail', ['locale' => app()->getLocale(), 'id' => $this->game->id]),
            tag: "game-reminder-{$this->game->id}",
        );
    }

    /**
     * No actor for block-list checking — this is a system notification.
     */
    public function getActor(): ?User
    {
        return null;
    }
}
