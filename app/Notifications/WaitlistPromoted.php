<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WaitlistPromoted extends Notification
{
    use HasUnsubscribeLink;

    public function __construct(
        public Game $game,
        public string $confirmationDeadline,
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
            ->subject(__('notifications.subject_waitlist_promoted', [
                'game' => $this->game->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_waitlist_promoted', [
                'game' => $this->game->name,
                'deadline' => $this->confirmationDeadline,
            ]))
            ->action(__('notifications.action_waitlist_promoted'), route('games.detail', ['locale' => $locale, 'id' => $this->game->id]))
            ->line($this->unsubscribeLine($notifiable, 'waitlist_promoted'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return [
            'type' => 'waitlist_promoted',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'confirmation_deadline' => $this->confirmationDeadline,
            'action_url' => route('games.detail', ['locale' => $locale, 'id' => $this->game->id]),
        ];
    }

    public function toPush(object $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.push_title_waitlist_promoted'),
            body: __('notifications.push_body_waitlist_promoted', [
                'game' => $this->game->name,
                'deadline' => $this->confirmationDeadline,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('games.detail', ['locale' => $locale, 'id' => $this->game->id]),
            tag: "waitlist-promoted-{$this->game->id}",
        );
    }
}
