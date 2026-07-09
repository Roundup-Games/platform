<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

class SessionAddedToCampaign extends BaseNotification
{
    use HasUnsubscribeLink;

    /**
     * @param  Game  $session  The session (Game model) added to the campaign
     * @param  Campaign  $campaign  The parent campaign
     */
    public function __construct(
        public Game $session,
        public Campaign $campaign,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $actionUrl = route('games.show', ['locale' => $locale, 'id' => $this->session]);

        return (new MailMessage)
            ->subject(__('notifications.subject_session_added_to_campaign', [
                'campaign' => $this->campaign->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_session_added_to_campaign', [
                'campaign' => $this->campaign->name,
            ]))
            ->action(__('notifications.action_session_added_to_campaign'), $actionUrl)
            ->line($this->unsubscribeLine($notifiable, 'session_added_to_campaign'));
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
            'type' => 'session_added_to_campaign',
            'session_id' => $this->session->id,
            'session_name' => $this->session->name,
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
            'action_url' => route('games.show', ['locale' => $locale, 'id' => $this->session]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * This is a system-generated notification — no specific actor.
     * Returns the campaign owner as the closest actor, or null.
     */
    public function getActor(): ?User
    {
        return $this->campaign->owner;
    }

    /**
     * Get the push notification representation.
     * Not applicable for this notification type.
     */
    public function toPush(User $notifiable): ?PushPayload
    {
        return null;
    }
}
