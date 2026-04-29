<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WaitlistExpiredRejected extends Notification
{
    use HasUnsubscribeLink;

    public function __construct(
        public Game $game,
        public int $confirmationAttempts,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [DatabaseChannel::class, MailChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_waitlist_expired_rejected', [
                'game' => $this->game->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_waitlist_expired_rejected', [
                'game' => $this->game->name,
                'attempts' => $this->confirmationAttempts,
            ]))
            ->action(__('notifications.action_browse_games'), route('games.index', ['locale' => $locale]))
            ->line($this->unsubscribeLine($notifiable, 'confirmation_expired'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return [
            'type' => 'waitlist_expired_rejected',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'confirmation_attempts' => $this->confirmationAttempts,
            'action_url' => route('games.index', ['locale' => $locale]),
        ];
    }

    public function toPush(object $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.push_title_waitlist_expired_rejected'),
            body: __('notifications.push_body_waitlist_expired_rejected', [
                'game' => $this->game->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('games.index', ['locale' => $locale]),
            tag: "waitlist-rejected-{$this->game->id}",
        );
    }
}
