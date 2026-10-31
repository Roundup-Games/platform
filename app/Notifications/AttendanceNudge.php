<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;

class AttendanceNudge extends BaseNotification
{
    /**
     * @param  Game  $game  The game the nudge is about
     * @param  Carbon  $deadline  The reporting-window close as a UTC instant —
     *                            rendered in each recipient's wall clock (R089).
     */
    public function __construct(
        public Game $game,
        public Carbon $deadline,
    ) {}

    /**
     * Recipient-facing deadline label (R089): the instant converts to the
     * recipient's wall clock — every channel renders the same label.
     */
    private function deadlineLabel(User $notifiable): string
    {
        return format_date($this->deadline, 'datetime', $notifiable->timezone ?? community_timezone());
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        // Recipient-zone date (R089): format_date converts the UTC instant to
        // the notifiable's wall clock — the calendar day differs across zones.
        $date = format_date($this->game->date_time, 'date', $notifiable->timezone ?? community_timezone());

        return (new MailMessage)
            ->subject(__('notifications.subject_attendance_nudge', [
                'game' => $this->game->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_attendance_nudge', [
                'game' => $this->game->name,
                'date' => $date,
                'deadline' => $this->deadlineLabel($notifiable),
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
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'attendance_nudge',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'deadline' => $this->deadlineLabel($notifiable),
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
        return $this->pushPayload(
            __('notifications.category_attendance_nudge'),
            __('notifications.push_body_attendance_nudge', [
                'game' => $this->game->name,
                'deadline' => $this->deadlineLabel($notifiable),
            ]),
            route('games.show', [
                'locale' => $this->resolveLocale($notifiable),
                'id' => $this->game->id,
            ]),
            "attendance-nudge-{$this->game->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        return $this->discordEmbed(
            __('notifications.category_attendance_nudge'),
            route('games.show', [
                'locale' => $this->resolveLocale($notifiable),
                'id' => $this->game->id,
            ]),
            __('notifications.push_body_attendance_nudge', [
                'game' => $this->game->name,
                'deadline' => $this->deadlineLabel($notifiable),
            ]),
        );
    }
}
