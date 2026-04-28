<?php

namespace App\Notifications;

use App\Dto\PushPayload;

use App\Models\Team;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamMemberRemoved extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  Team  $team  The team the user was removed from
     * @param  User  $remover  The user who performed the removal
     */
    public function __construct(
        public Team $team,
        public User $remover,
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
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_team_member_removed', [
                'team' => $this->team->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_team_member_removed', [
                'team' => $this->team->name,
            ]))
            ->action(__('notifications.action_team_member_removed'), route('teams.browse', ['locale' => $locale]))
            ->line($this->unsubscribeLine($notifiable, 'team_member_removed'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return [
            'type' => 'team_member_removed',
            'entity_type' => 'team',
            'entity_id' => $this->team->id,
            'entity_name' => $this->team->name,
            'remover_id' => $this->remover->id,
            'remover_name' => $this->remover->name,
            'action_url' => route('teams.browse', ['locale' => $locale]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns the remover since they triggered the action.
     */
    public function getActor(): User
    {
        return $this->remover;
    }

    /**
     * Get the push notification representation.
     * Not applicable for this notification type.
     */
    public function toPush(object $notifiable): ?PushPayload
    {
        return null;
    }
}
