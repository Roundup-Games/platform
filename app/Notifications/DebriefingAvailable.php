<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class DebriefingAvailable extends BaseNotification
{
    /**
     * @param  Game  $game  The game that was completed with debriefing tools
     */
    public function __construct(
        public Game $game,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);

        return (new MailMessage)
            ->subject(__('notifications.subject_debriefing_available', [
                'game' => $this->game->name,
            ]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_debriefing_available', [
                'game' => $this->game->name,
            ]))
            ->action(__('games.action_submit_debriefing'), route('games.show', ['locale' => $locale, 'id' => $this->game]))
            ->line($this->unsubscribeLine($notifiable, 'debriefing_available'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'debriefing_available',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'action_url' => route('games.show', ['locale' => $locale, 'id' => $this->game]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns the game owner as the closest actor for game events.
     */
    public function getActor(): ?User
    {
        return $this->game->owner;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): ?PushPayload
    {
        return $this->pushPayload(
            __('notifications.push_title_debriefing_available'),
            __('notifications.subject_debriefing_available', [
                'game' => $this->game->name,
            ]),
            route('games.show', ['locale' => $this->resolveLocale($notifiable), 'id' => $this->game]),
            "debriefing-{$this->game->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        return $this->discordEmbed(
            __('notifications.push_title_debriefing_available'),
            route('games.show', ['locale' => $this->resolveLocale($notifiable), 'id' => $this->game]),
            __('notifications.subject_debriefing_available', [
                'game' => $this->game->name,
            ]),
        );
    }
}
