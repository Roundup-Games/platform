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
    use HasUnsubscribeLink;

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
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $date = $this->game->date_time?->format('M j, Y') ?? '';
        $status = $this->report->status->label();

        return (new MailMessage)
            ->subject(__('notifications.subject_attendance_reported', [
                'game' => $this->game->name,
            ]))
            ->greeting(__('common.field_hey_name', ['name' => $notifiable->name ?? $notifiable->email]))
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
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

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
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $status = $this->report->status->label();

        return new PushPayload(
            title: __('notifications.push_title_attendance_reported'),
            body: __('notifications.push_body_attendance_reported', [
                'status' => $status,
                'game' => $this->game->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]),
            tag: "attendance-reported-{$this->report->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $status = $this->report->status->label();

        return DiscordWebhookPayload::embed([
            'title' => __('notifications.push_title_attendance_reported'),
            'url' => route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]),
            'description' => __('notifications.push_body_attendance_reported', [
                'status' => $status,
                'game' => $this->game->name,
            ]),
            'color' => 0x5865F2,
        ]);
    }
}
