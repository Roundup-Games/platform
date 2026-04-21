<?php

namespace App\Notifications;

use App\Models\Team;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitation extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  Team  $team  The team the user is invited to
     * @param  User  $inviter  The user who sent the invitation
     */
    public function __construct(
        public Team $team,
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
        $actionUrl = route('teams.detail', $this->team->slug);

        return (new MailMessage)
            ->subject(__('notifications.subject_team_invitation', [
                'inviter' => $this->inviter->name,
                'team' => $this->team->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_team_invitation', [
                'inviter' => $this->inviter->name,
                'team' => $this->team->name,
            ]))
            ->action(__('notifications.action_team_invitation'), $actionUrl)
            ->line($this->unsubscribeLine($notifiable, 'team_invitation'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'team_invitation',
            'team_id' => $this->team->id,
            'team_name' => $this->team->name,
            'team_slug' => $this->team->slug,
            'inviter_id' => $this->inviter->id,
            'inviter_name' => $this->inviter->name,
            'action_url' => route('teams.detail', $this->team->slug),
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
