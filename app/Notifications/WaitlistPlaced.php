<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Notification sent when a participant is placed on the waitlist for a full entity.
 *
 * Distinct from WaitlistPromoted (which fires when a seat opens and the user is
 * promoted OFF the waitlist, carrying a confirmation deadline). Placement has no
 * deadline and no action required — it is purely informational — so it lives in
 * its own category (WaitlistPlacement) with mail off by default.
 *
 * Fires from two paths:
 *  - OverflowRouter::placeAcceptedInvitee() — an invite is accepted but the
 *    entity filled between invite and accept.
 *  - HandlesApplicationSubmission — a public application to a full entity.
 */
class WaitlistPlaced extends BaseNotification
{
    use RoutesGameOrCampaign;

    public function __construct(
        public Game|Campaign $entity,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);

        return (new MailMessage)
            ->subject(__('notifications.subject_waitlist_placed', [
                'game' => $this->entity->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_waitlist_placed', [
                'game' => $this->entity->name,
            ]))
            ->action(__('notifications.action_view_'.$this->getEntityType()), $this->getEntityRoute($locale))
            ->line($this->unsubscribeLine($notifiable, 'waitlist_placement'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'waitlist_placed',
            'entity_type' => $this->getEntityType(),
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'action_url' => $this->getEntityRoute($locale),
        ];
    }

    public function toPush(User $notifiable): PushPayload
    {
        return $this->pushPayload(
            __('notifications.push_title_waitlist_placed'),
            __('notifications.push_body_waitlist_placed', [
                'game' => $this->entity->name,
            ]),
            $this->getEntityRoute($this->resolveLocale($notifiable)),
            "waitlist-placed-{$this->getEntityType()}-{$this->entity->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        return $this->discordEmbed(
            __('notifications.push_title_waitlist_placed'),
            $this->getEntityRoute($this->resolveLocale($notifiable)),
            __('notifications.push_body_waitlist_placed', [
                'game' => $this->entity->name,
            ]),
        );
    }

    /**
     * The entity owner is the closest actor for block-list purposes — a user
     * who blocked the owner will not be notified about their entity's waitlist.
     */
    public function getActor(): ?User
    {
        return $this->entity->owner;
    }
}
