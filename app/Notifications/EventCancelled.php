<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Event;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Event cancellation notice for registrants (M063/S03).
 *
 * Sent by EventLifecycleService::cancel() — the single lifecycle entry
 * point shared by the organizer-facing ManageEvent flows (Cancel action
 * and the status select in save()) and the Filament admin edit form —
 * so every active registrant (status != cancelled) is notified exactly
 * once per cancellation transition, regardless of which surface
 * performed it. The service's already-cancelled guard prevents a
 * second dispatch for the same cancellation.
 *
 * Dispatched through NotificationService with the EventRegistration
 * category, so per-user channel preferences, suppression lists, and
 * dispatch observability all apply (same contract as
 * EventRegistrationConfirmed).
 */
class EventCancelled extends BaseNotification
{
    use HasUnsubscribeLink;

    public function __construct(
        public Event $event,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $event = $this->event;

        $mail = (new MailMessage)
            ->subject(__('events.field_event_cancelled_name', [
                'name' => $event->name ?? __('events.content_event'),
            ]))
            ->greeting(__('common.field_hey_name', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('events.content_event_cancelled_notice', [
                'event' => $event->name ?? __('events.content_event'),
            ]))
            ->line('**'.__('events.content_event_details').'**')
            ->line('**'.__('events.content_event').':** '.$event->name);

        $date = format_date($event->start_date);
        if ($event->end_date !== null && $event->start_date?->ne($event->end_date)) {
            $date .= ' — '.format_date($event->end_date);
        }
        $mail->line('**'.__('common.field_date').':** '.$date);

        if ($event->venue_name !== null && $event->venue_name !== '') {
            $mail->line('**'.__('common.content_venue').':** '.$event->venue_name);
        }

        return $mail
            ->action(__('events.action_view_event_details'), route('events.detail', [
                'locale' => $locale,
                'slug' => $event->slug,
            ]))
            ->line(__('events.content_we_re_sorry_for_any_inconvenience'))
            ->line($this->unsubscribeLine($notifiable, 'event_registration'));
    }

    /**
     * Get the array representation of the notification.
     *
     * data.type carries the NotificationCategory discriminator so
     * NotificationQueryService::resolveCategory() resolves the bell label
     * for this unified-style class name (M063/S03 contract, same as
     * EventRegistrationConfirmed).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $event = $this->event;

        return [
            'type' => 'event_registration',
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'entity_name' => $event->name ?? __('events.content_event'),
            'action_url' => route('events.detail', [
                'locale' => $locale,
                'slug' => $event->slug,
            ]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns the event organizer as the closest actor for status changes.
     */
    public function getActor(): ?User
    {
        return $this->event->organizer;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $event = $this->event;

        return new PushPayload(
            title: __('events.content_event_cancelled'),
            body: __('events.field_event_cancelled_name', [
                'name' => $event->name ?? __('events.content_event'),
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('events.detail', ['locale' => $locale, 'slug' => $event->slug]),
            tag: "event-cancelled-{$event->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $event = $this->event;

        return DiscordWebhookPayload::embed([
            'title' => __('events.content_event_cancelled'),
            'url' => route('events.detail', ['locale' => $locale, 'slug' => $event->slug]),
            'description' => __('events.content_event_cancelled_notice', [
                'event' => $event->name ?? __('events.content_event'),
            ]),
            'color' => 0xED4245,
        ]);
    }
}
