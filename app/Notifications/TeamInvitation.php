<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Team;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class TeamInvitation extends BaseNotification
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
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $actionUrl = route('teams.detail', ['locale' => $locale, 'slug' => $this->team->slug]);

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
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return [
            'type' => 'team_invitation',
            'team_id' => $this->team->id,
            'team_name' => $this->team->name,
            'team_slug' => $this->team->slug,
            'inviter_id' => $this->inviter->id,
            'inviter_name' => $this->inviter->name,
            'action_url' => route('teams.detail', ['locale' => $locale, 'slug' => $this->team->slug]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): User
    {
        return $this->inviter;
    }

    /**
     * Get the push notification representation.
     * Not applicable for this notification type.
     */
    public function toPush(User $notifiable): ?PushPayload
    {
        return null;
    }
}
