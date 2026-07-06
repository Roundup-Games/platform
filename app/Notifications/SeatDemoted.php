<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\CapacityService;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Notifies a player their approved seat was reclaimed because the host lowered
 * max_players below the approved count.
 *
 * Dispatched by {@see CapacityService::demote()} per displaced
 * player, OUTSIDE the DB transaction, each wrapped in try/catch+Log so a
 * notification failure can never roll back the demotion (mirrors the
 * {@see WaitlistPromoted} pattern).
 *
 * Unlike {@see ParticipantRemoved} (which returns null from toPush), a seat
 * loss is high-priority and time-sensitive — the player may want to act — so
 * this notification DOES push. {@see getActor()} returns the game owner (the
 * host who performed the demotion) so the NotificationService block-list check
 * can suppress dispatch when the recipient has blocked the host.
 */
class SeatDemoted extends BaseNotification
{
    use HasUnsubscribeLink, RoutesGameOrCampaign;

    public function __construct(
        public Game|Campaign $entity,
        public string $reason,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_seat_demoted', [
                'entity' => $this->entity->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_seat_demoted', [
                'entity' => $this->entity->name,
                'reason' => $this->reason,
            ]))
            ->action(__('notifications.action_seat_demoted'), $this->getEntityRoute($locale))
            ->line($this->unsubscribeLine($notifiable, 'seat_demoted'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return [
            'type' => 'seat_demoted',
            'entity_type' => $this->getEntityType(),
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'reason' => $this->reason,
            'action_url' => $this->getEntityRoute($locale),
        ];
    }

    public function toPush(User $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.push_title_seat_demoted'),
            body: __('notifications.push_body_seat_demoted', [
                'game' => $this->entity->name,
                'reason' => $this->reason,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: $this->getEntityRoute($locale),
            tag: "seat-demoted-{$this->getEntityType()}-{$this->entity->id}",
        );
    }

    /**
     * The actor is the game owner (the host who demoted) for block-list checking.
     */
    public function getActor(): ?User
    {
        return $this->entity->owner;
    }
}
