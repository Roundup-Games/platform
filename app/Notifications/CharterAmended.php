<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A campaign's charter was amended — the copy distinguishes material
 * amendments (every signature reset; re-signing needed) from minor ones
 * (signatures carry; informational) (M070 concept §3, D234 closure).
 *
 * Actor is the campaign owner (the GM); block-list filtering in
 * NotificationService applies.
 */
class CharterAmended extends BaseNotification
{
    use HasUnsubscribeLink;

    /**
     * @param  Campaign  $campaign  The campaign the charter belongs to
     * @param  SessionZeroSurvey  $charter  The amended charter
     * @param  SessionZeroSurveyVersion  $version  The newly published version
     * @param  bool  $material  Material amendment (re-agreement required)
     */
    public function __construct(
        public Campaign $campaign,
        public SessionZeroSurvey $charter,
        public SessionZeroSurveyVersion $version,
        public bool $material,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__($this->material ? 'notifications.subject_charter_amended_material' : 'notifications.subject_charter_amended_minor', [
                'campaign' => $this->campaign->name,
            ]))
            ->greeting(__('common.field_hey_name', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__($this->material ? 'notifications.body_charter_amended_material' : 'notifications.body_charter_amended_minor', [
                'campaign' => $this->campaign->name,
            ]))
            ->action(__($this->material ? 'notifications.action_charter_review' : 'notifications.action_charter_view'), $this->actionUrl($locale))
            ->line($this->unsubscribeLine($notifiable, 'charter_amended'));
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
            'type' => 'charter_amended',
            'entity_type' => 'campaign',
            'entity_id' => $this->campaign->id,
            'entity_name' => $this->campaign->name,
            'actor_id' => $this->campaign->owner_id,
            'charter_uuid' => $this->charter->uuid,
            'material' => $this->material,
            'version' => $this->version->version_number,
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
            title: __('notifications.push_title_charter_amended'),
            body: __($this->material ? 'notifications.push_body_charter_amended_material' : 'notifications.push_body_charter_amended_minor', [
                'campaign' => $this->campaign->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: $this->actionUrl($locale),
            tag: "charter-amended-{$this->charter->uuid}-v{$this->version->version_number}",
        );
    }

    /**
     * Get the Discord DM representation: a rich embed carrying the
     * review/re-sign ask, mirroring the mail/push copy.
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return DiscordWebhookPayload::embed([
            'title' => __($this->material ? 'notifications.subject_charter_amended_material' : 'notifications.subject_charter_amended_minor', [
                'campaign' => $this->campaign->name,
            ]),
            'url' => $this->actionUrl($locale),
            'description' => __($this->material ? 'notifications.body_charter_amended_material' : 'notifications.body_charter_amended_minor', [
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
