<?php

namespace App\Notifications;

use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GameInvitation extends Notification
{
    /**
     * @param  Game  $game  The game the user is invited to
     * @param  User  $inviter  The user who sent the invitation
     */
    public function __construct(
        public Game $game,
        public User $inviter,
    ) {}

    /**
     * Get the notification's delivery channels.
     * When dispatched via NotificationService, channels are resolved
     * from user preferences; this serves as a fallback default.
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
        $actionUrl = route('games.detail', $this->game->id);

        return (new MailMessage)
            ->subject(__('notifications.subject_game_invitation', [
                'inviter' => $this->inviter->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_game_invitation', [
                'inviter' => $this->inviter->name,
                'game' => $this->game->name,
            ]))
            ->action(__('notifications.action_game_invitation'), $actionUrl);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'game_invitation',
            'game_id' => $this->game->id,
            'game_name' => $this->game->name,
            'inviter_id' => $this->inviter->id,
            'inviter_name' => $this->inviter->name,
            'action_url' => route('games.detail', $this->game->id),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): User
    {
        return $this->inviter;
    }
}
