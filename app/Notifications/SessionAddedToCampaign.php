<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SessionAddedToCampaign extends Notification
{
    /**
     * @param  Game  $session  The session (Game model) added to the campaign
     * @param  Campaign  $campaign  The parent campaign
     */
    public function __construct(
        public Game $session,
        public Campaign $campaign,
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
        $actionUrl = route('games.detail', $this->session->id);

        return (new MailMessage)
            ->subject(__('notifications.subject_session_added_to_campaign', [
                'campaign' => $this->campaign->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_session_added_to_campaign', [
                'campaign' => $this->campaign->name,
            ]))
            ->action(__('notifications.action_session_added_to_campaign'), $actionUrl);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'session_added_to_campaign',
            'session_id' => $this->session->id,
            'session_name' => $this->session->name,
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
            'action_url' => route('games.detail', $this->session->id),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * This is a system-generated notification — no specific actor.
     * Returns the campaign owner as the closest actor, or null.
     */
    public function getActor(): ?User
    {
        return $this->campaign->owner;
    }
}
