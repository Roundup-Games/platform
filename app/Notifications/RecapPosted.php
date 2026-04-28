<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecapPosted extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  Game  $game  The game the recap was written for
     * @param  User  $author  The host who wrote the recap
     */
    public function __construct(
        public Game $game,
        public User $author,
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
        return (new MailMessage)
            ->subject(__('notifications.subject_recap_posted', [
                'game' => $this->game->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_recap_posted', [
                'host' => $this->author->name,
                'game' => $this->game->name,
            ]))
            ->action(__('notifications.action_view_recap'), route('games.detail', $this->game->id))
            ->line($this->unsubscribeLine($notifiable, 'recap_posted'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'recap_posted',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'actor_id' => $this->author->id,
            'action_url' => route('games.detail', $this->game->id),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): ?User
    {
        return $this->author;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(object $notifiable): ?PushPayload
    {
        return new PushPayload(
            title: __('notifications.push_title_recap_posted'),
            body: __('notifications.push_body_recap_posted', [
                'host' => $this->author->name,
                'game' => $this->game->name,
            ]),
            icon: asset('images/logo.png'),
            url: route('games.detail', $this->game->id),
            tag: "recap_{$this->game->id}",
        );
    }
}
