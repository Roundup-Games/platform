<?php

namespace App\Notifications;

use App\Models\Game;
use App\Models\User;
use App\Dto\PushPayload;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GameUpdated extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  Game  $game  The game that was updated
     * @param  string[]  $changedFields  Human-readable list of changed field names
     */
    public function __construct(
        public Game $game,
        public array $changedFields = [],
    ) {}

    /**
     * Get the notification's delivery channels.
     * When dispatched via NotificationService, channels are resolved
     * from user preferences; this serves as a fallback default.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [DatabaseChannel::class, MailChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();
        $fields = implode(', ', $this->changedFields);

        return (new MailMessage)
            ->subject(__('notifications.subject_game_updated', [
                'game' => $this->game->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_game_updated', [
                'game' => $this->game->name,
                'fields' => $fields,
            ]))
            ->action(__('notifications.action_view_game'), route('games.show', ['locale' => $locale, 'id' => $this->game->id]))
            ->line($this->unsubscribeLine($notifiable, 'game_updated'));
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
            'type' => 'game_updated',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'changed_fields' => $this->changedFields,
            'action_url' => route('games.show', ['locale' => $locale, 'id' => $this->game->id]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): ?User
    {
        return $this->game->owner;
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
