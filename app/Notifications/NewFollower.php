<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\User;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewFollower extends Notification
{
    use HasUnsubscribeLink;

    public function __construct(
        public User $follower,
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
        $actionUrl = route('profile.public', ['locale' => app()->getLocale(), 'user' => $this->follower]);

        return (new MailMessage)
            ->subject(__('notifications.subject_new_follower', ['follower' => $this->follower->name]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_new_follower', ['follower' => $this->follower->name]))
            ->action(__('notifications.action_new_follower'), $actionUrl)
            ->line($this->unsubscribeLine($notifiable, 'new_follower'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'new_follower',
            'follower_id' => $this->follower->id,
            'follower_name' => $this->follower->name,
            'action_url' => route('profile.public', ['locale' => app()->getLocale(), 'user' => $this->follower]),
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
    public function toPush(object $notifiable): PushPayload
    {
        return new PushPayload(
            title: __('notifications.push_title_new_follower'),
            body: __('notifications.push_body_new_follower', [
                'follower' => $this->follower->name,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('profile.public', ['locale' => app()->getLocale(), 'user' => $this->follower]),
            tag: "new-follower-{$this->follower->id}",
        );
    }
}
