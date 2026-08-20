<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class ConfirmationExpired extends BaseNotification
{
    use HasUnsubscribeLink, RoutesGameOrCampaign;

    public function __construct(
        public Game|Campaign $entity,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_confirmation_expired', [
                'game' => $this->entity->name,
            ]))
            ->greeting(__('common.field_hey_name', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_confirmation_expired', [
                'game' => $this->entity->name,
            ]))
            ->action(__('notifications.action_view_game'), $this->getEntityRoute($locale))
            ->line($this->unsubscribeLine($notifiable, 'confirmation_expired'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

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
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.category_confirmation_expired'),
            body: __('notifications.push_body_confirmation_expired', [
                'game' => $this->entity->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: $this->getEntityRoute($locale),
            tag: "confirmation-expired-{$this->getEntityType()}-{$this->entity->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return DiscordWebhookPayload::embed([
            'title' => __('notifications.category_confirmation_expired'),
            'url' => $this->getEntityRoute($locale),
            'description' => __('notifications.push_body_confirmation_expired', [
                'game' => $this->entity->name,
            ]),
            'color' => 0x5865F2,
        ]);
    }
}
