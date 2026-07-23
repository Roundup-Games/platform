<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use Illuminate\Notifications\Channels\DatabaseChannel;

/**
 * Notification sent to game participants when their session
 * is starting within the next hour.
 *
 * Dispatched by the SendSessionReminders artisan command, not by user actions.
 * Database + push notification — no mail channel.
 *
 * Decision D125 (hybrid model): the two built-in reminders (24h / 1h) pass
 * `window` and use lang-key copy. Organizer-authored custom reminders
 * (game_reminders rows, swept by the T06 custom window) pass `customMessage`
 * to override the body with organizer-written copy while reusing the same
 * title, category, and routing — so preference filtering / block-lists /
 * structured logging apply unchanged (MEM855).
 */
class SessionReminder extends BaseNotification
{
    /**
     * @param  Game  $game  The game that is starting soon
     * @param  string  $window  '24h' or '1h' — controls the notification message
     * @param  string|null  $customMessage  Organizer-authored body copy; when
     *                                      non-null it overrides the lang-key body (custom reminders — D125).
     *                                      Falls back to lang-key copy otherwise (built-in 24h/1h reminders).
     */
    public function __construct(
        public Game $game,
        public string $window = '1h',
        public ?string $customMessage = null,
    ) {}

    /**
     * Database + push, no mail channel.
     * Overrides BaseNotification's default (which includes mail) because this
     * notification has no toMail() implementation.
     *
     * @return array<int, string>
     */
    protected function supportedChannels(): array
    {
        return [DatabaseChannel::class, PushChannel::class];
    }

    /**
     * Get the database representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        $payload = [
            'type' => 'session_reminder',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'date_time' => $this->game->date_time?->toIso8601String(),
            'window' => $this->window,
            'action_url' => route('games.show', ['locale' => $locale, 'id' => $this->game]),
        ];

        // Custom reminders (D125) carry organizer-written copy into the
        // database payload so the in-app rendering shows the organizer's words.
        if ($this->customMessage !== null) {
            $payload['custom_message'] = $this->customMessage;
        }

        return $payload;
    }

    /**
     * Get the push notification representation.
     *
     * Times are converted from UTC (app timezone) to Europe/Berlin (DACH
     * target audience) and include the timezone abbreviation for clarity,
     * e.g. "3:00 PM CEST".
     */
    public function toPush(User $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $timezone = $notifiable->timezone ?? 'Europe/Berlin';

        $time = $this->game->date_time
            ? $this->game->date_time->setTimezone($timezone)->format('g:i A T')
            : '';

        $titleKey = $this->window === '24h'
            ? 'notifications.push_title_session_reminder_24h'
            : 'notifications.push_title_session_reminder';

        // Custom reminders (D125): organizer-written copy overrides the
        // lang-key body when provided; the title stays the lang key so the
        // push is recognizably a session reminder. Built-in reminders and
        // custom reminders without a message fall back to lang-key copy.
        if ($this->customMessage !== null) {
            $body = $this->customMessage;
        } else {
            $bodyKey = $this->window === '24h'
                ? 'notifications.push_body_session_reminder_24h'
                : 'notifications.push_body_session_reminder';

            $body = __($bodyKey, [
                'game' => $this->game->name,
                'time' => $time,
            ]);
        }

        return new PushPayload(
            title: __($titleKey),
            body: $body,
            icon: '/icons/pwa-192x192.png',
            url: route('games.show', ['locale' => $locale, 'id' => $this->game]),
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
