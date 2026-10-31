<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Enums\AttendanceStatus;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class AttendanceResolved extends BaseNotification
{
    /**
     * @param  Game  $game  The game the attendance was resolved for
     * @param  AttendanceStatus  $status  The resolved attendance status for this user
     */
    public function __construct(
        public Game $game,
        public AttendanceStatus $status,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        // Recipient-zone date (R089): format_date converts the stored UTC
        // instant to the notifiable's wall clock — the calendar day differs
        // across zones.
        $date = format_date($this->game->date_time, 'date', $notifiable->timezone ?? community_timezone());
        $status = $this->status->label();

        return (new MailMessage)
            ->subject(__('notifications.subject_attendance_resolved', [
                'game' => $this->game->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_attendance_resolved', [
                'game' => $this->game->name,
                'date' => $date,
                'status' => $status,
            ]))
            ->action(__('notifications.action_view_game'), route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]))
            ->line($this->unsubscribeLine($notifiable, 'attendance_resolved'));
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
            'type' => 'attendance_resolved',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'attendance_status' => $this->status->value,
            'date_time' => $this->game->date_time?->toIso8601String(),
            'action_url' => route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Resolution is system-initiated, so no actor to block.
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
        $status = $this->status->label();

        return $this->pushPayload(
            __('notifications.category_attendance_resolved'),
            __('notifications.push_body_attendance_resolved', [
                'game' => $this->game->name,
                'status' => $status,
            ]),
            route('games.show', [
                'locale' => $this->resolveLocale($notifiable),
                'id' => $this->game->id,
            ]),
            "attendance-resolved-{$this->game->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $status = $this->status->label();

        return $this->discordEmbed(
            __('notifications.category_attendance_resolved'),
            route('games.show', [
                'locale' => $this->resolveLocale($notifiable),
                'id' => $this->game->id,
            ]),
            __('notifications.push_body_attendance_resolved', [
                'game' => $this->game->name,
                'status' => $status,
            ]),
        );
    }
}
