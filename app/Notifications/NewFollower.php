<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class NewFollower extends BaseNotification
{
    public function __construct(
        public User $follower,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        $actionUrl = route('profile.show-authenticated', ['locale' => $locale, 'user' => $this->follower]);

        return (new MailMessage)
            ->subject(__('notifications.subject_new_follower', ['follower' => $this->follower->name]))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_new_follower', ['follower' => $this->follower->name, 'brand' => is_string($b = config('company.display_name')) ? $b : '']))
            ->action(__('notifications.action_new_follower'), $actionUrl)
            ->line($this->unsubscribeLine($notifiable, 'new_follower'));
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
            'type' => 'new_follower',
            'follower_id' => $this->follower->id,
            'follower_name' => $this->follower->name,
            'action_url' => route('profile.show-authenticated', ['locale' => $locale, 'user' => $this->follower]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): User
    {
        return $this->follower;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        return $this->pushPayload(
            __('notifications.category_new_follower'),
            __('notifications.subject_new_follower', [
                'follower' => $this->follower->name,
            ]),
            route('profile.show-authenticated', ['locale' => $this->resolveLocale($notifiable), 'user' => $this->follower]),
            "new-follower-{$this->follower->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        return $this->discordEmbed(
            __('notifications.category_new_follower'),
            route('profile.show-authenticated', ['locale' => $this->resolveLocale($notifiable), 'user' => $this->follower]),
            __('notifications.subject_new_follower', [
                'follower' => $this->follower->name,
            ]),
        );
    }
}
