<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
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
    use HasUnsubscribeLink, RoutesGameOrCampaign;

    public function __construct(
        public Game|Campaign $entity,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_waitlist_placed', [
                'game' => $this->entity->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_waitlist_placed', [
                'game' => $this->entity->name,
            ]))
            ->action(__('notifications.action_waitlist_placed'), $this->getEntityRoute($locale))
            ->line($this->unsubscribeLine($notifiable, 'waitlist_placement'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

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
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.push_title_waitlist_placed'),
            body: __('notifications.push_body_waitlist_placed', [
                'game' => $this->entity->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: $this->getEntityRoute($locale),
            tag: "waitlist-placed-{$this->getEntityType()}-{$this->entity->id}",
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
