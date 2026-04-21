<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CampaignInvitation extends Notification
{
    /**
     * @param  Campaign  $campaign  The campaign the user is invited to
     * @param  User  $inviter  The user who sent the invitation
     */
    public function __construct(
        public Campaign $campaign,
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
        $actionUrl = route('campaigns.detail', $this->campaign->id);

        return (new MailMessage)
            ->subject(__('notifications.subject_campaign_invitation', [
                'inviter' => $this->inviter->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_campaign_invitation', [
                'inviter' => $this->inviter->name,
                'campaign' => $this->campaign->name,
            ]))
            ->action(__('notifications.action_campaign_invitation'), $actionUrl);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'campaign_invitation',
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
            'inviter_id' => $this->inviter->id,
            'inviter_name' => $this->inviter->name,
            'action_url' => route('campaigns.detail', $this->campaign->id),
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
