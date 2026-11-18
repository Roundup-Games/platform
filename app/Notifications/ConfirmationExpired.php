<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordPalette;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class ConfirmationExpired extends BaseNotification
{
    use RoutesGameOrCampaign;

    public function __construct(
        public Game|Campaign $entity,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);

        $mail = (new MailMessage)
            ->subject(__('notifications.subject_confirmation_expired', [
                'game' => $this->entity->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_confirmation_expired', [
                'game' => $this->entity->name,
            ]))
            ->action(__('notifications.action_view_game'), $this->actionUrlWithMarker($notifiable, $this->getEntityRoute($locale)))
            ->line($this->unsubscribeLine($notifiable, 'confirmation_expired'));

        return $this->withPreheader($mail, 'notifications.preheader_confirmation_expired');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'confirmation_expired',
            'entity_type' => $this->getEntityType(),
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'action_url' => $this->getEntityRoute($locale),
        ];
    }

    public function toPush(User $notifiable): PushPayload
    {
        return $this->pushPayload(
            __('notifications.category_confirmation_expired'),
            __('notifications.push_body_confirmation_expired', [
                'game' => $this->entity->name,
            ]),
            $this->actionUrlWithMarker($notifiable, $this->getEntityRoute($this->resolveLocale($notifiable))),
            "confirmation-expired-{$this->getEntityType()}-{$this->entity->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        return $this->discordEmbed(
            __('notifications.category_confirmation_expired'),
            $this->getEntityRoute($this->resolveLocale($notifiable)),
            __('notifications.push_body_confirmation_expired', [
                'game' => $this->entity->name,
            ]),
        );
    }

    /**
     * D280 semantic layer: cancellation red — the confirmation window expired; the seat outcome changed adversely.
     */
    protected function semanticColor(): int
    {
        return DiscordPalette::CANCELLATION;
    }
}
