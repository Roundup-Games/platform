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
 * Every approved participant has signed the charter's current version —
 * the one state that unlocks the GM's countersignature (CharterSigningService::
 * countersign() refuses while anyone is pending). Until this notification the
 * GM could only discover the moment by polling the workspace roster.
 *
 * Fires on the TRANSITION only (the signature that completed the roster),
 * per version: a material amendment resets the signatures, and the next
 * completion on the new version notifies again.
 *
 * Direction is the reverse of CharterPublished/CharterAmended: the recipient
 * is the campaign owner (the GM), and the actor is the participant whose
 * signature completed the roster — block-list filtering in NotificationService
 * applies to that actor.
 */
class CharterFullySigned extends BaseNotification
{
    use RoutesCharter;

    /**
     * @param  Campaign  $campaign  The campaign the charter belongs to
     * @param  SessionZeroSurvey  $charter  The fully signed charter
     * @param  SessionZeroSurveyVersion  $version  The version every participant signed
     * @param  User  $signer  The participant whose signature completed the roster
     */
    public function __construct(
        public Campaign $campaign,
        public SessionZeroSurvey $charter,
        public SessionZeroSurveyVersion $version,
        public User $signer,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('notifications.subject_charter_fully_signed', [
                'campaign' => $this->campaign->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_charter_fully_signed', [
                'campaign' => $this->campaign->name,
            ]))
            ->action(__('notifications.action_charter_countersign'), $this->actionUrlWithMarker($notifiable, $this->charterUrl($this->resolveLocale($notifiable))))
            ->line($this->unsubscribeLine($notifiable, 'charter_fully_signed'));

        return $this->withPreheader($mail, 'notifications.preheader_charter_fully_signed');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'charter_fully_signed',
            'entity_type' => 'campaign',
            'entity_id' => $this->campaign->id,
            'entity_name' => $this->campaign->name,
            'actor_id' => $this->signer->id,
            // Bell sentence actor (NotificationQueryService ACTOR_*_KEYS).
            'signer_id' => $this->signer->id,
            'signer_name' => $this->signer->name,
            'charter_uuid' => $this->charter->uuid,
            'version' => $this->version->version_number,
            'action_url' => $this->charterUrl($locale),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): ?User
    {
        return $this->signer;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): ?PushPayload
    {
        return $this->pushPayload(
            __('notifications.push_title_charter_fully_signed'),
            __('notifications.push_body_charter_fully_signed', [
                'campaign' => $this->campaign->name,
            ]),
            $this->actionUrlWithMarker($notifiable, $this->charterUrl($this->resolveLocale($notifiable))),
            "charter-fully-signed-{$this->charter->uuid}-v{$this->version->version_number}",
        );
    }

    /**
     * Get the Discord DM representation: a rich embed carrying the
     * countersign ask, mirroring the mail/push copy.
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        return $this->discordEmbed(
            __('notifications.subject_charter_fully_signed', [
                'campaign' => $this->campaign->name,
            ]),
            $this->charterUrl($this->resolveLocale($notifiable)),
            __('notifications.body_charter_fully_signed', [
                'campaign' => $this->campaign->name,
            ]),
        );
    }
}
