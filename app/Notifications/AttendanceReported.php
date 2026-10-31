<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class AttendanceReported extends BaseNotification
{
    /**
     * @param  Game  $game  The game the attendance report is for
     * @param  AttendanceReport  $report  The attendance report that was filed
     */
    public function __construct(
        public Game $game,
        public AttendanceReport $report,
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
        $status = $this->report->status->label();

        return (new MailMessage)
            ->subject(__('notifications.subject_attendance_reported', [
                'game' => $this->game->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_attendance_reported', [
                'game' => $this->game->name,
                'date' => $date,
                'status' => $status,
            ]))
            ->action(__('notifications.action_open_dispute_report'), route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]))
            ->line($this->unsubscribeLine($notifiable, 'attendance_reported'));
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
            'type' => 'attendance_reported',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'report_id' => $this->report->id,
            'attendance_status' => $this->report->status->value,
            'date_time' => $this->game->date_time?->toIso8601String(),
            'action_url' => route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns the reporter as the actor.
     */
    public function getActor(): ?User
    {
        return $this->report->reporter;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        $status = $this->report->status->label();

        return $this->pushPayload(
            __('notifications.push_title_attendance_reported'),
            __('notifications.push_body_attendance_reported', [
                'status' => $status,
                'game' => $this->game->name,
            ]),
            route('games.show', [
                'locale' => $this->resolveLocale($notifiable),
                'id' => $this->game->id,
            ]),
            "attendance-reported-{$this->report->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $status = $this->report->status->label();

        return $this->discordEmbed(
            __('notifications.push_title_attendance_reported'),
            route('games.show', [
                'locale' => $this->resolveLocale($notifiable),
                'id' => $this->game->id,
            ]),
            __('notifications.push_body_attendance_reported', [
                'status' => $status,
                'game' => $this->game->name,
            ]),
        );
    }
}
