<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CampaignCompleted extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  Campaign  $campaign  The campaign that was completed
     */
    public function __construct(
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
        return (new MailMessage)
            ->subject(__('notifications.subject_campaign_completed', [
                'campaign' => $this->campaign->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_campaign_completed', [
                'campaign' => $this->campaign->name,
            ]))
            ->action(__('notifications.action_campaign_completed'), route('campaigns.detail', $this->campaign->id))
            ->line($this->unsubscribeLine($notifiable, 'campaign_completed'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'campaign_completed',
            'entity_type' => 'campaign',
            'entity_id' => $this->campaign->id,
            'entity_name' => $this->campaign->name,
            'action_url' => route('campaigns.detail', $this->campaign->id),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns the campaign owner as the closest actor for status changes.
     */
    public function getActor(): ?User
    {
        return $this->campaign->owner;
    }
}
