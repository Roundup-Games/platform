<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Announcement-published notice for registrants (M063/S03).
 *
 * Sent to users holding an active registration (status != cancelled)
 * when an announcement transitions to published. Deliberately fires
 * ONLY for VISIBILITY_REGISTERED announcements: "all"-tier
 * announcements are already visible on the public event detail page to
 * everyone (including anonymous visitors), so notifying every
 * registrant about them would be broadcast noise rather than a perk;
 * registered-tier content is the tier whose audience registrants would
 * otherwise miss. Private-tier announcements are for organizers/admins
 * and never notify registrants.
 *
 * Triggering is owned by EventAnnouncementObserver (the single
 * transition chokepoint covering the organizer quick-action in
 * EventAnnouncements::publishAnnouncement(), the is_published toggle in
 * the Livewire edit form, the Filament AnnouncementsRelationManager
 * edit form, and direct-published creates) which delegates dispatch to
 * EventLifecycleService::announcePublication() — so every publication
 * transition notifies exactly once regardless of surface.
 *
 * Dispatched through NotificationService with the EventRegistration
 * category, so per-user channel preferences, suppression lists, and
 * dispatch observability all apply (same contract as
 * EventRegistrationConfirmed and EventCancelled).
 *
 * Recipients include pending (e.g. awaiting payment) registrations per
 * the slice's shared "active = not cancelled" definition; note the
 * event page additionally requires a confirmed registration to SEE
 * registered-tier content (EventAnnouncement::scopeVisibleTo), which
 * pending registrants gain once payment confirms.
 */
class EventAnnouncementPublished extends BaseNotification
{
    use HasUnsubscribeLink;

    public function __construct(
        public EventAnnouncement $announcement,
        public Event $event,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $title = $this->resolveTitle($locale);

        return (new MailMessage)
            ->subject(__('events.field_new_announcement_name', [
                'name' => $this->event->name ?? __('events.content_event'),
            ]))
            ->greeting(__('common.field_hey_name', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('events.content_new_announcement_for_attendees', [
                'event' => $this->event->name ?? __('events.content_event'),
            ]))
            ->line('**'.__('events.content_announcement').':** '.$title)
            ->action(__('events.action_view_event_details'), route('events.detail', [
                'locale' => $locale,
                'slug' => $this->event->slug,
            ]))
            ->line($this->unsubscribeLine($notifiable, 'event_registration'));
    }

    /**
     * Get the array representation of the notification.
     *
     * data.type carries the NotificationCategory discriminator so
     * NotificationQueryService::resolveCategory() resolves the bell label
     * for this unified-style class name (M063/S03 contract, same as
     * EventRegistrationConfirmed and EventCancelled).
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
            'entity_name' => $this->event->name ?? __('events.content_event'),
            'announcement_id' => $this->announcement->id,
            'action_url' => route('events.detail', [
                'locale' => $locale,
                'slug' => $this->event->slug,
            ]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns the announcement author as the actor for this publication.
     */
    public function getActor(): ?User
    {
        return $this->announcement->author;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return new PushPayload(
            title: __('events.content_new_announcement'),
            body: __('events.field_new_announcement_name', [
                'name' => $this->event->name ?? __('events.content_event'),
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('events.detail', ['locale' => $locale, 'slug' => $this->event->slug]),
            tag: "event-announcement-{$this->announcement->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $title = $this->resolveTitle($locale);

        return DiscordWebhookPayload::embed([
            'title' => __('events.content_new_announcement'),
            'url' => route('events.detail', ['locale' => $locale, 'slug' => $this->event->slug]),
            'description' => __('events.content_new_announcement_for_attendees', [
                'event' => $this->event->name ?? __('events.content_event'),
            ])."\n\n**{$title}**",
            'color' => 0x57F287,
        ]);
    }

    /**
     * Resolve the translatable announcement title in the recipient's
     * locale, falling back to the English translation (same pattern as
     * ICalFeedRenderer::resolveTranslation()).
     */
    private function resolveTitle(string $locale): string
    {
        $title = $this->announcement->getTranslation('title', $locale, false);

        if (is_string($title) && $title !== '') {
            return $title;
        }

        $fallback = $this->announcement->getTranslation('title', 'en', false);

        return is_string($fallback) && $fallback !== '' ? $fallback : '';
    }
}
