<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Notification sent when a host manually promotes a benched participant to an
 * approved seat.
 *
 * Distinct from WaitlistPromoted: bench promotion is host-curated (not FIFO),
 * carries no confirmation deadline, and the wording credits the host's choice
 * rather than an automatic queue advance.
 */
class PromotedFromBench extends BaseNotification
{
    use HasUnsubscribeLink, RoutesGameOrCampaign;

    public function __construct(
        public Game|Campaign $entity,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_promoted_from_bench', [
                'game' => $this->entity->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_promoted_from_bench', [
                'game' => $this->entity->name,
            ]))
            ->action(__('notifications.action_promoted_from_bench'), $this->getEntityRoute($locale))
            ->line($this->unsubscribeLine($notifiable, 'bench_updates'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return [
            'type' => 'promoted_from_bench',
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
            title: __('notifications.push_title_promoted_from_bench'),
            body: __('notifications.push_body_promoted_from_bench', [
                'game' => $this->entity->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: $this->getEntityRoute($locale),
            tag: "promoted-from-bench-{$this->getEntityType()}-{$this->entity->id}",
        );
    }
}
