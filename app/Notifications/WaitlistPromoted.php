<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WaitlistPromoted extends Notification
{
    use HasUnsubscribeLink;

    public function __construct(
        public Game|Campaign $entity,
        public string $confirmationDeadline = '',
    ) {}

    protected function getEntityType(): string
    {
        return $this->entity instanceof Campaign ? 'campaign' : 'game';
    }

    protected function getEntityRoute(string $locale): string
    {
        if ($this->entity instanceof Campaign) {
            return route('campaigns.detail', ['locale' => $locale, 'id' => $this->entity->id]);
        }

        return route('games.detail', ['locale' => $locale, 'id' => $this->entity->id]);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [DatabaseChannel::class, MailChannel::class];
    }

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
            tag: "waitlist-promoted-{$this->entity->id}",
        );
    }
}
