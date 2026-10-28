<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class EntityCancelled extends BaseNotification
{
    use RoutesGameOrCampaign;

    /**
     * @param  Game|Campaign  $entity  The game or campaign that was cancelled
     */
    public function __construct(
        public Game|Campaign $entity,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        $type = $this->getEntityType();

        $mail = (new MailMessage)
            ->subject(__("notifications.subject_{$type}_cancelled", [
                $type => $this->entity->name,
            ]))
            ->greeting($this->greetingLine($notifiable));

        // GameCancelled includes date in body; CampaignCancelled does not
        if ($type === 'game') {
            $date = $this->entity->date_time?->format('M j, Y') ?? '';
            $mail->line(__('notifications.body_game_cancelled', [
                'game' => $this->entity->name,
                'date' => $date,
            ]));
        } else {
            $mail->line(__('notifications.body_campaign_cancelled', [
                'campaign' => $this->entity->name,
            ]));
        }

        $browseAction = $this->entity instanceof Campaign
            ? __('notifications.action_browse_campaigns')
            : __('notifications.action_browse_games');

        $mail->action($browseAction, route("{$type}s.index", ['locale' => $locale]))
            ->line($this->unsubscribeLine($notifiable, "{$type}_cancelled"));

        return $mail;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);
        $type = $this->getEntityType();

        $data = [
            'type' => "{$type}_cancelled",
            'entity_type' => $type,
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'action_url' => route("{$type}s.index", ['locale' => $locale]),
        ];

        // GameCancelled includes date_time; CampaignCancelled does not
        if ($type === 'game' && $this->entity instanceof Game) {
            $data['date_time'] = $this->entity->date_time?->toIso8601String();
        }

        return $data;
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns the entity owner as the closest actor for status changes.
     */
    public function getActor(): ?User
    {
        return $this->entity->owner;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        $type = $this->getEntityType();

        return $this->pushPayload(
            __("common.activity_type_{$type}_canceled"),
            __("notifications.subject_{$type}_cancelled", [
                $type => $this->entity->name,
            ]),
            route("{$type}s.show", ['locale' => $this->resolveLocale($notifiable), 'id' => $this->entity]),
            "{$type}-cancelled-{$this->entity->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $type = $this->getEntityType();

        return $this->discordEmbed(
            __("common.activity_type_{$type}_canceled"),
            route("{$type}s.show", ['locale' => $this->resolveLocale($notifiable), 'id' => $this->entity]),
            __("notifications.subject_{$type}_cancelled", [
                $type => $this->entity->name,
            ]),
        );
    }
}
