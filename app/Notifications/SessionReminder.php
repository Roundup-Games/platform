<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\PushChannel;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Channels\DatabaseChannel;

/**
 * Notification sent to game participants when their session
 * is starting within the next hour.
 *
 * Dispatched by the SendSessionReminders artisan command, not by user actions.
 * Database + push notification — no mail channel.
 */
class SessionReminder extends BaseNotification
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
     * Database + push + Discord, no mail channel.
     * Overrides BaseNotification's default (which includes mail) because this
     * notification has no toMail() implementation. The toDiscord() embed below
     * is intentionally reachable — session reminders are high-signal and a
     * linked Discord user should receive them.
     *
     * @return array<int, string>
     */
    protected function supportedChannels(): array
    {
        return [DatabaseChannel::class, PushChannel::class, DiscordChannel::class];
    }

    /**
     * Get the database representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'session_reminder',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'date_time' => $this->game->date_time?->toIso8601String(),
            'window' => $this->window,
            'action_url' => route('games.show', ['locale' => $locale, 'id' => $this->game]),
        ];
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        $copy = $this->reminderCopy($notifiable);

        return $this->pushPayload(
            $copy['title'],
            $copy['body'],
            route('games.show', ['locale' => $this->resolveLocale($notifiable), 'id' => $this->game]),
            "game-reminder-{$this->window}-{$this->game->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $copy = $this->reminderCopy($notifiable);

        return $this->discordEmbed(
            $copy['title'],
            route('games.show', ['locale' => $this->resolveLocale($notifiable), 'id' => $this->game]),
            $copy['body'],
        );
    }

    /**
     * Window-scoped copy shared by toPush() and toDiscord(), composed once
     * (both channels used to duplicate this block verbatim).
     *
     * Times are converted from UTC (app timezone) to the recipient's timezone
     * (default Europe/Berlin, DACH target audience) and include the timezone
     * abbreviation for clarity, e.g. "3:00 PM CEST".
     *
     * @return array{title: string, body: string}
     */
    private function reminderCopy(User $notifiable): array
    {
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

        return [
            'title' => __($titleKey),
            'body' => __($bodyKey, [
                'game' => $this->game->name,
                'time' => $time,
            ]),
        ];
    }

    /**
     * No actor for block-list checking — this is a system notification.
     */
    public function getActor(): ?User
    {
        return null;
    }
}
