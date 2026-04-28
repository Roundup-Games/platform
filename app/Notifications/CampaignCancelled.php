<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CampaignCancelled extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  Campaign  $campaign  The campaign that was cancelled
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
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_campaign_cancelled', [
                'campaign' => $this->campaign->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_campaign_cancelled', [
                'campaign' => $this->campaign->name,
            ]))
            ->action(__('notifications.action_campaign_cancelled'), route('campaigns.index', ['locale' => $locale]))
            ->line($this->unsubscribeLine($notifiable, 'campaign_cancelled'));
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
            'type' => 'campaign_cancelled',
            'entity_type' => 'campaign',
            'entity_id' => $this->campaign->id,
            'entity_name' => $this->campaign->name,
            'action_url' => route('campaigns.index', ['locale' => $locale]),
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

    /**
     * Get the push notification representation.
     */
    public function toPush(object $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.push_title_campaign_cancelled'),
            body: __('notifications.push_body_campaign_cancelled', [
                'campaign' => $this->campaign->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('campaigns.detail', ['locale' => $locale, 'id' => $this->campaign->id]),
            tag: "campaign-cancelled-{$this->campaign->id}",
        );
    }
}
