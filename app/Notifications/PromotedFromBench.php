<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
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
    use RoutesGameOrCampaign;

    public function __construct(
        public Game|Campaign $entity,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);

        return (new MailMessage)
            ->subject(__('notifications.subject_promoted_from_bench', [
                'game' => $this->entity->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_promoted_from_bench', [
                'game' => $this->entity->name,
            ]))
            ->action(__('notifications.action_view_'.$this->getEntityType()), $this->getEntityRoute($locale))
            ->line($this->unsubscribeLine($notifiable, 'bench_updates'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

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
        return $this->pushPayload(
            __('notifications.push_title_promoted_from_bench'),
            __('notifications.push_body_promoted_from_bench', [
                'game' => $this->entity->name,
            ]),
            $this->getEntityRoute($this->resolveLocale($notifiable)),
            "promoted-from-bench-{$this->getEntityType()}-{$this->entity->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        return $this->discordEmbed(
            __('notifications.push_title_promoted_from_bench'),
            $this->getEntityRoute($this->resolveLocale($notifiable)),
            __('notifications.push_body_promoted_from_bench', [
                'game' => $this->entity->name,
            ]),
        );
    }
}
