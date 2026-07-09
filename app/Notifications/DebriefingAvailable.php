<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class DebriefingAvailable extends BaseNotification
{
    use HasUnsubscribeLink;

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
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_debriefing_available', [
                'game' => $this->game->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_debriefing_available', [
                'game' => $this->game->name,
            ]))
            ->action(__('notifications.action_submit_debriefing'), route('games.show', ['locale' => $locale, 'id' => $this->game]))
            ->line($this->unsubscribeLine($notifiable, 'debriefing_available'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

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
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.push_title_debriefing_available'),
            body: __('notifications.push_body_debriefing_available', [
                'game' => $this->game->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('games.show', ['locale' => $locale, 'id' => $this->game]),
            tag: "debriefing-{$this->game->id}",
        );
    }
}
