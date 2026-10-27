<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A campaign's charter was published — approved participants are asked
 * to read it and sign (M070 concept §3/§4, D234 closure).
 *
 * Actor is the campaign owner (the GM); block-list filtering in
 * NotificationService applies.
 */
class CharterPublished extends BaseNotification
{
    /**
     * @param  Campaign  $campaign  The campaign the charter belongs to
     * @param  SessionZeroSurvey  $charter  The published charter
     */
    public function __construct(
        public Campaign $campaign,
        public SessionZeroSurvey $charter,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_charter_published', [
                'campaign' => $this->campaign->name,
            ]))
            ->greeting(__('common.field_hey_name', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_charter_published', [
                'campaign' => $this->campaign->name,
            ]))
            ->action(__('notifications.action_charter_sign'), $this->actionUrl($locale))
            ->line($this->unsubscribeLine($notifiable, 'charter_published'));
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
            'type' => 'charter_published',
            'entity_type' => 'campaign',
            'entity_id' => $this->campaign->id,
            'entity_name' => $this->campaign->name,
            'actor_id' => $this->campaign->owner_id,
            'charter_uuid' => $this->charter->uuid,
            'action_url' => $this->actionUrl($locale),
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
     */
    public function toPush(User $notifiable): ?PushPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.push_title_charter_published'),
            body: __('notifications.push_body_charter_published', [
                'campaign' => $this->campaign->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: $this->actionUrl($locale),
            tag: "charter-published-{$this->charter->uuid}",
        );
    }

    /**
     * Get the Discord DM representation: a rich embed carrying the
     * read-and-sign ask, mirroring the mail/push copy.
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return DiscordWebhookPayload::embed([
            'title' => __('notifications.subject_charter_published', [
                'campaign' => $this->campaign->name,
            ]),
            'url' => $this->actionUrl($locale),
            'description' => __('notifications.body_charter_published', [
                'campaign' => $this->campaign->name,
            ]),
            // Discord blurple — the app's Discord brand colour.
            'color' => 0x5865F2,
        ]);
    }

    private function actionUrl(string $locale): string
    {
        return route('charter.view', ['locale' => $locale, 'uuid' => $this->charter->uuid]);
    }
}
