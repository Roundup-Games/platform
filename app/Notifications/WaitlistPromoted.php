<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use Illuminate\Notifications\Messages\MailMessage;

class WaitlistPromoted extends BaseNotification
{
    use HasUnsubscribeLink, RoutesGameOrCampaign;

    public function __construct(
        public Game|Campaign $entity,
        public string $confirmationDeadline = '',
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('notifications.subject_waitlist_promoted', [
                'game' => $this->entity->name,
            ]))
            ->greeting(__('notifications.email_greeting', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('notifications.body_waitlist_promoted', [
                'game' => $this->entity->name,
                'deadline' => $this->confirmationDeadline,
            ]))
            ->action(__('notifications.action_waitlist_promoted'), $this->getEntityRoute($locale))
            ->line($this->unsubscribeLine($notifiable, 'waitlist_promoted'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return [
            'type' => 'waitlist_promoted',
            'entity_type' => $this->getEntityType(),
            'entity_id' => $this->entity->id,
            'entity_name' => $this->entity->name,
            'confirmation_deadline' => $this->confirmationDeadline,
            'action_url' => $this->getEntityRoute($locale),
        ];
    }

    public function toPush(object $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language?->value ?? app()->getLocale();

        return new PushPayload(
            title: __('notifications.push_title_waitlist_promoted'),
            body: __('notifications.push_body_waitlist_promoted', [
                'game' => $this->entity->name,
                'deadline' => $this->confirmationDeadline,
            ]),
            icon: '/icons/pwa-192x192.png',
            url: $this->getEntityRoute($locale),
            tag: "waitlist-promoted-{$this->getEntityType()}-{$this->entity->id}",
        );
    }
}
