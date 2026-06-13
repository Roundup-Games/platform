<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Enums\AttendanceStatus;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class AttendanceResolved extends BaseNotification
{
    use HasUnsubscribeLink;

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
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $date = $this->game->date_time?->format('M j, Y') ?? '';
        $status = $this->status->label();

        return (new MailMessage)
            ->subject(__('notifications.subject_attendance_resolved', [
                'game' => $this->game->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
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
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

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
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $status = $this->status->label();

        return new PushPayload(
            title: __('notifications.push_title_attendance_resolved'),
            body: __('notifications.push_body_attendance_resolved', [
                'game' => $this->game->name,
                'status' => $status,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('games.show', [
                'locale' => $locale,
                'id' => $this->game->id,
            ]),
            tag: "attendance-resolved-{$this->game->id}",
        );
    }
}
