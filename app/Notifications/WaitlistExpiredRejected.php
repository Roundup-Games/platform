<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class WaitlistExpiredRejected extends BaseNotification
{
    use RoutesGameOrCampaign;

    public function __construct(
        public Game|Campaign $entity,
        public int $confirmationAttempts,
    ) {}

    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);

        return (new MailMessage)
            ->subject(__('notifications.subject_waitlist_expired_rejected', [
                'game' => $this->entity->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_waitlist_expired_rejected', [
                'game' => $this->entity->name,
                'attempts' => $this->confirmationAttempts,
            ]))
            ->action(__('notifications.action_browse_games'), route('games.index', ['locale' => $locale]))
            ->line($this->unsubscribeLine($notifiable, 'confirmation_expired'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'waitlist_expired_rejected',
            'entity_type' => $this->getEntityType(),
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'confirmation_attempts' => $this->confirmationAttempts,
            'action_url' => route('games.index', ['locale' => $locale]),
        ];
    }

    public function toPush(User $notifiable): PushPayload
    {
        return $this->pushPayload(
            __('notifications.push_title_waitlist_expired_rejected'),
            __('notifications.push_body_waitlist_expired_rejected', [
                'game' => $this->entity->name,
            ]),
            route('games.index', ['locale' => $this->resolveLocale($notifiable)]),
            "waitlist-rejected-{$this->getEntityType()}-{$this->entity->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        return $this->discordEmbed(
            __('notifications.push_title_waitlist_expired_rejected'),
            route('games.index', ['locale' => $this->resolveLocale($notifiable)]),
            __('notifications.push_body_waitlist_expired_rejected', [
                'game' => $this->entity->name,
            ]),
        );
    }
}
