<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisputeResolved extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  Game  $game  The game the dispute was about
     * @param  string  $resolution  The dispute resolution: 'resolved_favor' or 'upheld'
     */
    public function __construct(
        public Game $game,
        public string $resolution,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [DatabaseChannel::class, MailChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->game->date_time?->format('M j, Y') ?? '';
        $resolved = $this->resolution === 'resolved_favor';

        $subject = $resolved
            ? __('notifications.subject_dispute_resolved_favor', ['game' => $this->game->name])
            : __('notifications.subject_dispute_upheld', ['game' => $this->game->name]);

        $body = $resolved
            ? __('notifications.body_dispute_resolved_favor', ['game' => $this->game->name, 'date' => $date])
            : __('notifications.body_dispute_upheld', ['game' => $this->game->name, 'date' => $date]);

        return (new MailMessage)
            ->subject($subject)
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line($body)
            ->action(__('notifications.action_view_game'), route('games.detail', [
                'locale' => app()->getLocale(),
                'id' => $this->game->id,
            ]))
            ->line($this->unsubscribeLine($notifiable, 'dispute_resolved'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'dispute_resolved',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'resolution' => $this->resolution,
            'date_time' => $this->game->date_time?->toIso8601String(),
            'action_url' => route('games.detail', [
                'locale' => app()->getLocale(),
                'id' => $this->game->id,
            ]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Dispute resolution is system-initiated, so no actor to block.
     */
    public function getActor(): ?User
    {
        return null;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(object $notifiable): PushPayload
    {
        $resolved = $this->resolution === 'resolved_favor';

        $title = $resolved
            ? __('notifications.push_title_dispute_resolved_favor')
            : __('notifications.push_title_dispute_upheld');

        $body = $resolved
            ? __('notifications.push_body_dispute_resolved_favor', ['game' => $this->game->name])
            : __('notifications.push_body_dispute_upheld', ['game' => $this->game->name]);

        return new PushPayload(
            title: $title,
            body: $body,
            icon: '/icons/pwa-192x192.png',
            url: route('games.detail', [
                'locale' => app()->getLocale(),
                'id' => $this->game->id,
            ]),
            tag: "dispute-resolved-{$this->game->id}",
        );
    }
}
