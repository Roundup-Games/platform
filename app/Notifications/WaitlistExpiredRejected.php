<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordPalette;
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

        $mail = (new MailMessage)
            ->subject(__('notifications.subject_waitlist_expired_rejected', [
                'game' => $this->entity->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_waitlist_expired_rejected', [
                'game' => $this->entity->name,
                'attempts' => $this->confirmationAttempts,
            ]))
            ->action(__('notifications.action_browse_games'), $this->actionUrlWithMarker($notifiable, route('games.index', ['locale' => $locale])))
            ->line($this->unsubscribeLine($notifiable, 'confirmation_expired'));

        return $this->withPreheader($mail, 'notifications.preheader_waitlist_expired_rejected');
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
            $this->actionUrlWithMarker($notifiable, route('games.index', ['locale' => $this->resolveLocale($notifiable)])),
            "waitlist-rejected-{$this->getEntityType()}-{$this->entity->id}",
        );
    }

    /**
     * Expiry bucket (D280): the seat expired out from under the recipient —
     * the same negative-outcome family as ConfirmationExpired, so the DM
     * carries the palette red, not the brand emerald the no-bucket default
     * would paint on it.
     */
    protected function semanticColor(): int
    {
        return DiscordPalette::CANCELLATION;
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
