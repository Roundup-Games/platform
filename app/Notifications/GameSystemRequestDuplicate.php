<?php

namespace App\Notifications;

use App\Dto\PushPayload;

use App\Models\GameSystem;
use App\Models\GameSystemRequest;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GameSystemRequestDuplicate extends Notification
{
    use HasUnsubscribeLink;

    /**
     * @param  GameSystemRequest  $request  The duplicate game system request
     * @param  GameSystem  $existingSystem  The existing game system that matches
     */
    public function __construct(
        public GameSystemRequest $request,
        public GameSystem $existingSystem,
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
        $existingUrl = route('game-systems.show', ['locale' => $locale, 'slug' => $this->existingSystem->slug]);

        return (new MailMessage)
            ->subject(__('notifications.subject_game_system_request_duplicate'))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_game_system_request_duplicate', [
                'name' => $this->request->name,
                'existing' => $this->existingSystem->name,
            ]))
            ->action(__('notifications.action_view_game_system'), $existingUrl)
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
            'type' => 'game_system_request_duplicate',
            'request_id' => $this->request->id,
            'existing_game_system_id' => $this->existingSystem->id,
            'existing_game_system_name' => $this->existingSystem->name,
            'existing_game_system_slug' => $this->existingSystem->slug,
            'message' => __('notifications.body_game_system_request_duplicate', [
                'name' => $this->request->name,
                'existing' => $this->existingSystem->name,
            ]),
            'action_url' => route('game-systems.show', ['locale' => $locale, 'slug' => $this->existingSystem->slug]),
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
