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
     * @param  string  $window  '24h' or '1h' — controls the notification message
     */
    public function __construct(
        public Game $game,
        public string $window = '1h',
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
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return [
            'type' => 'session_reminder',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'date_time' => $this->game->date_time?->toIso8601String(),
            'action_url' => route('games.detail', ['locale' => $locale, 'id' => $this->game->id]),
        ];
    }

    /**
     * Get the push notification representation.
     *
     * Times are converted from UTC (app timezone) to Europe/Berlin (DACH
     * target audience) and include the timezone abbreviation for clarity,
     * e.g. "3:00 PM CEST".
     */
    public function toPush(object $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();
        $timezone = $notifiable->timezone ?? 'Europe/Berlin';

        $time = $this->game->date_time
            ? $this->game->date_time->setTimezone($timezone)->format('g:i A T')
            : '';

        $titleKey = $this->window === '24h'
            ? 'notifications.push_title_session_reminder_24h'
            : 'notifications.push_title_session_reminder';

        $bodyKey = $this->window === '24h'
            ? 'notifications.push_body_session_reminder_24h'
            : 'notifications.push_body_session_reminder';

        return new PushPayload(
            title: __($titleKey),
            body: __($bodyKey, [
                'game' => $this->game->name,
                'time' => $time,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('games.detail', ['locale' => $locale, 'id' => $this->game->id]),
            tag: "game-reminder-{$this->window}-{$this->game->id}",
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
