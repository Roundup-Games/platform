<?php

namespace App\Notifications;

use App\Dto\PushPayload;

use App\Models\GameSystem;
use App\Models\User;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Notifications\Messages\MailMessage;

class GameSystemRequestApproved extends BaseNotification
{
    use HasUnsubscribeLink;

    /**
     * @param  Ticket  $ticket  The Escalated ticket representing the game system request
     * @param  GameSystem  $gameSystem  The newly created game system
     */
    public function __construct(
        public Ticket $ticket,
        public GameSystem $gameSystem,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();
        $gameSystemUrl = route('game-systems.show', ['locale' => $locale, 'slug' => $this->gameSystem->slug]);
        $createGameUrl = route('games.create', ['locale' => $locale]) . '?game_system_id=' . $this->gameSystem->id;

        return (new MailMessage)
            ->subject(__('notifications.subject_game_system_request_approved', [
                'name' => $this->gameSystem->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_game_system_request_approved', [
                'name' => $this->gameSystem->name,
            ]))
            ->action(__('notifications.action_create_game'), $createGameUrl)
            ->line($this->unsubscribeLine($notifiable, 'game_system_request'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return [
            'type' => 'game_system_request_approved',
            'ticket_id' => $this->ticket->id,
            'game_system_id' => $this->gameSystem->id,
            'game_system_name' => $this->gameSystem->name,
            'game_system_slug' => $this->gameSystem->slug,
            'message' => __('notifications.body_game_system_request_approved', [
                'name' => $this->gameSystem->name,
            ]),
            'action_url' => route('game-systems.show', ['locale' => $locale, 'slug' => $this->gameSystem->slug]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns null since this is a system notification, not triggered by a user.
     */
    public function getActor(): ?User
    {
        return null;
    }

    /**
     * Get the push notification representation.
     * Not applicable for this notification type.
     */
    public function toPush(object $notifiable): ?PushPayload
    {
        return null;
    }
}
