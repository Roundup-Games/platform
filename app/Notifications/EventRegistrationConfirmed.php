<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;
use LogicException;

/**
 * Registration confirmation for an event (M063/S03).
 *
 * Absorbs the former registration-confirmation mailable (removed in
 * M063/S03): the mail body reuses the same localized copy
 * (emails.* / events.* / common.* keys). Sent from BOTH
 * confirmation paths — the free RegisterForEvent::register() flow and the paid
 * PaddleWebhookController transaction.completed confirm — so a registrant
 * never gets silence after a successful registration.
 *
 * Dispatched through NotificationService with the EventRegistration category,
 * so per-user channel preferences, suppression lists, and dispatch
 * observability all apply. Division/registration-type detail lines from the
 * old mailable are gone — those columns were dropped in M063/S01.
 */
class EventRegistrationConfirmed extends BaseNotification
{
    use HasUnsubscribeLink;

    public function __construct(
        public EventRegistration $registration,
    ) {}

    /**
     * The confirmed event, guaranteed present. Every render channel needs
     * it; a registration whose event vanished is a data-integrity failure
     * worth a named exception, not a string-collapse of null into copy.
     */
    private function event(): Event
    {
        return $this->registration->event
            ?? throw new LogicException('Event registration is missing its event: '.$this->registration->id);
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $event = $this->event();

        $mail = (new MailMessage)
            ->subject(__('emails.field_event_registration_confirmed_name', [
                'name' => $event->name ?? __('events.content_event'),
            ]))
            ->greeting(__('common.field_hey_name', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__('events.content_you_re_all_set_for_event', [
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

        if ($event->contact_email !== null && $event->contact_email !== '') {
            $mail->line(__('common.content_questions').' '.__('emails.content_contact_the_organizer_at_email', [
                'email' => $event->contact_email,
            ]));
        }

        return $mail
            ->action(__('events.action_view_event_details'), route('events.detail', [
                'locale' => $locale,
                'slug' => $event->slug,
            ]))
            ->line(__('common.content_see_you_there'))
            ->line($this->unsubscribeLine($notifiable, 'event_registration'));
    }

    /**
     * Get the array representation of the notification.
     *
     * data.type carries the NotificationCategory discriminator so
     * NotificationQueryService::resolveCategory() resolves the bell label
     * for this unified-style class name (M063/S03 contract).
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $event = $this->event();

        return [
            'type' => 'event_registration',
            'entity_type' => 'event',
            'entity_id' => $event->id,
            'entity_name' => $event->name ?? __('events.content_event'),
            'registration_id' => $this->registration->id,
            'action_url' => route('events.detail', [
                'locale' => $locale,
                'slug' => $event->slug,
            ]),
        ];
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $event = $this->event();

        return new PushPayload(
            title: __('emails.content_event_registration_confirmed'),
            body: __('emails.field_event_registration_confirmed_name', [
                'name' => $event->name ?? __('events.content_event'),
            ]),
            icon: '/icons/pwa-192x192.png',
            url: route('events.detail', ['locale' => $locale, 'slug' => $event->slug]),
            tag: "event-registration-{$event->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push).
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();
        $event = $this->event();

        return DiscordWebhookPayload::embed([
            'title' => __('emails.content_event_registration_confirmed'),
            'url' => route('events.detail', ['locale' => $locale, 'slug' => $event->slug]),
            'description' => __('events.content_you_re_all_set_for_event', [
                'event' => $event->name ?? __('events.content_event'),
            ]),
            'color' => 0x5865F2,
        ]);
    }
}
