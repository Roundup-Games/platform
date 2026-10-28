<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class WaitlistPromoted extends BaseNotification
{
    use RoutesGameOrCampaign;

    public function __construct(
        public Game|Campaign $entity,
        public string $confirmationDeadline = '',
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);

        return (new MailMessage)
            ->subject(__('notifications.subject_waitlist_promoted', [
                'game' => $this->entity->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_waitlist_promoted', [
                'game' => $this->entity->name,
                'deadline' => $this->confirmationDeadline,
            ]))
            ->action(__('notifications.action_waitlist_promoted'), $this->getEntityRoute($locale))
            ->line($this->unsubscribeLine($notifiable, 'waitlist_promoted'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'waitlist_promoted',
            'entity_type' => $this->getEntityType(),
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'confirmation_deadline' => $this->confirmationDeadline,
            'action_url' => $this->getEntityRoute($locale),
        ];
    }

    public function toPush(User $notifiable): PushPayload
    {
        return $this->pushPayload(
            __('notifications.push_title_waitlist_promoted'),
            __('notifications.push_body_waitlist_promoted', [
                'game' => $this->entity->name,
                'deadline' => $this->confirmationDeadline,
            ]),
            $this->getEntityRoute($this->resolveLocale($notifiable)),
            "waitlist-promoted-{$this->getEntityType()}-{$this->entity->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        return $this->discordEmbed(
            __('notifications.push_title_waitlist_promoted'),
            $this->getEntityRoute($this->resolveLocale($notifiable)),
            __('notifications.push_body_waitlist_promoted', [
                'game' => $this->entity->name,
                'deadline' => $this->confirmationDeadline,
            ]),
        );
    }
}
