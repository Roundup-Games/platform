<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\User;
use App\Dto\PushPayload;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CampaignUpdated extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  Campaign  $campaign  The campaign that was updated
     * @param  string[]  $changedFields  Human-readable list of changed field names
     */
    public function __construct(
        public Campaign $campaign,
        public array $changedFields = [],
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
        $fields = implode(', ', $this->changedFields);

        return (new MailMessage)
            ->subject(__('notifications.subject_campaign_updated', [
                'campaign' => $this->campaign->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_campaign_updated', [
                'campaign' => $this->campaign->name,
                'fields' => $fields,
            ]))
            ->action(__('notifications.action_view_campaign'), route('campaigns.detail', $this->campaign->id))
            ->line($this->unsubscribeLine($notifiable, 'campaign_updated'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'campaign_updated',
            'entity_type' => 'campaign',
            'entity_id' => $this->campaign->id,
            'entity_name' => $this->campaign->name,
            'changed_fields' => $this->changedFields,
            'action_url' => route('campaigns.detail', $this->campaign->id),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): ?User
    {
        return $this->campaign->owner;
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
