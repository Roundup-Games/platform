<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordPalette;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class EntityCompleted extends BaseNotification
{
    use RoutesGameOrCampaign;

    /**
     * @param  Game|Campaign  $entity  The game or campaign that was completed
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
            ->subject(__("notifications.subject_{$type}_completed", [
                $type => $this->entity->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__("notifications.body_{$type}_completed", [
                $type => $this->entity->name,
            ]))
            ->action(__("notifications.action_view_{$type}"), $this->actionUrlWithMarker($notifiable, route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity])))
            ->line($this->unsubscribeLine($notifiable, "{$type}_completed"));

        return $this->withPreheader($mail, "notifications.preheader_{$type}_completed", [
            $type => $this->entity->name,
        ]);
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

        return [
            'type' => "{$type}_completed",
            'entity_type' => $type,
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'action_url' => route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity]),
        ];
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
     * Discord opted out (D130): this type has no push surface, so it has no Discord surface either.
     */
    public function toDiscord(User $notifiable): ?DiscordWebhookPayload
    {
        return null;
    }

    /**
     * D280 semantic layer: ambient grey — completed entity, informational only. toDiscord() is a null opt-out (D130) today; the mapping is declared so a future DM inherits the right color.
     */
    protected function semanticColor(): int
    {
        return DiscordPalette::AMBIENT;
    }
}
