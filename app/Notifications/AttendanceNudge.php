<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class AttendanceNudge extends BaseNotification
{
    use HasUnsubscribeLink;

    /**
     * @param  Game  $game  The game the nudge is about
     * @param  string  $deadline  Human-readable deadline time (e.g. "tomorrow at 6:00 PM")
     */
    public function __construct(
        public Game $game,
        public string $deadline,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $date = $this->game->date_time?->format('M j, Y') ?? '';

        return (new MailMessage)
            ->subject(__('notifications.subject_attendance_nudge', [
                'game' => $this->game->name,
            ]))
            ->greeting(__('common.field_hey_name', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_attendance_nudge', [
                'game' => $this->game->name,
                'date' => $date,
                'deadline' => $this->deadline,
            ]))
            ->action(__('notifications.action_view_game'), route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]))
            ->line($this->unsubscribeLine($notifiable, 'attendance_nudge'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return [
            'type' => 'attendance_nudge',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'deadline' => $this->deadline,
            'date_time' => $this->game->date_time?->toIso8601String(),
            'action_url' => route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Nudge is system-initiated, so no actor to block.
     */
    public function getActor(): ?User
    {
        return null;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.category_attendance_nudge'),
            body: __('notifications.push_body_attendance_nudge', [
                'game' => $this->game->name,
                'deadline' => $this->deadline,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]),
            tag: "attendance-nudge-{$this->game->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return DiscordWebhookPayload::embed([
            'title' => __('notifications.category_attendance_nudge'),
            'url' => route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]),
            'description' => __('notifications.push_body_attendance_nudge', [
                'game' => $this->game->name,
                'deadline' => $this->deadline,
            ]),
            'color' => 0x5865F2,
        ]);
    }
}
