<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Event;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Co-organizer delegation notice (M063/S04, D156).
 *
 * Sent by EventDelegationService::grantCoOrganizer() when a user is
 * granted the event-scoped 'Event Admin' role. Per D156 the grant is
 * silent and immediate — there is no invitation to accept; the target
 * can manage the event the moment the organizer clicks. This
 * notification exists so they find out: it deep-links to the manage
 * route (events.manage) rather than the public detail page, because
 * the actionable next step for a new co-organizer is the management
 * surface itself.
 *
 * Duplicate grants are no-ops upstream (the service skips them), so
 * this notification fires exactly once per (user, event) delegation.
 *
 * Dispatched through NotificationService under the EventRegistration
 * category (the events preference group) so per-user channel
 * preferences, suppression lists, block lists, and dispatch
 * observability apply — the same contract as EventRegistrationConfirmed
 * and EventCancelled. data.type carries the category discriminator so
 * NotificationQueryService::resolveCategory() resolves the bell label
 * for this unified-style class name (same pattern as
 * EventAnnouncementPublished).
 */
class EventCoOrganizerAdded extends BaseNotification
{
    use HasUnsubscribeLink;

    public function __construct(
        public Event $event,
        public User $actor,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return (new MailMessage)
            ->subject(__('events.content_you_ve_been_added_as_co_organizer_of_event', [
                'event' => $this->eventName(),
            ]))
            ->greeting(__('common.field_hey_name', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('events.content_you_ve_been_added_as_co_organizer_of_event', [
                'event' => '**'.$this->eventName().'**',
            ]))
            ->action(__('events.action_manage_event'), $this->manageUrl($locale))
            ->line($this->unsubscribeLine($notifiable, 'event_registration'));
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
            'type' => 'event_registration',
            'entity_type' => 'event',
            'entity_id' => $this->event->id,
            'entity_name' => $this->eventName(),
            'action_url' => $this->manageUrl($locale),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns the user who granted the co-organizer role.
     */
    public function getActor(): ?User
    {
        return $this->actor;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return new PushPayload(
            title: __('events.content_co_organizer_added'),
            body: __('events.content_you_ve_been_added_as_co_organizer_of_event', [
                'event' => $this->eventName(),
            ]),
            icon: '/icons/pwa-192x192.png',
            url: $this->manageUrl($locale),
            tag: "event-co-organizer-{$this->event->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return DiscordWebhookPayload::embed([
            'title' => __('events.content_co_organizer_added'),
            'url' => $this->manageUrl($locale),
            'description' => __('events.content_you_ve_been_added_as_co_organizer_of_event', [
                'event' => $this->eventName(),
            ]),
            'color' => 0x57F287,
        ]);
    }

    private function manageUrl(string $locale): string
    {
        return route('events.manage', ['locale' => $locale, 'slug' => $this->event->slug]);
    }

    private function eventName(): string
    {
        return $this->event->name ?? __('events.content_event');
    }
}
