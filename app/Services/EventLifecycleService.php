<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\NotificationCategory;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\User;
use App\Notifications\EventAnnouncementPublished;
use App\Notifications\EventCancelled;
use Illuminate\Support\Facades\Log;

/**
 * Single owner of event lifecycle transitions and their registrant
 * communication side effects (M063/S03).
 *
 * Both cancellation surfaces route through cancel():
 *   - ManageEvent::cancelEvent() (dedicated Cancel action) and the status
 *     <select> inside ManageEvent::save()
 *   - the Filament EventResource edit form (EditEvent::handleRecordUpdate)
 *
 * Because every path funnels here, an event cancellation notifies every
 * active registrant exactly once. The already-cancelled guard makes the
 * method idempotent: a double call (double click, redelivery, form
 * resubmission of an already-cancelled event) is a logged no-op instead
 * of a second dispatch. A genuine re-cancellation after a cancelled →
 * draft revert is treated as a new transition and notifies again — by
 * then new registrations may exist.
 *
 * Recipients are users with an active registration (status != cancelled),
 * matching the duplicate-registration and capacity semantics used
 * elsewhere (RegisterForEvent, ManageRegistrations). Dispatch goes
 * through NotificationService so channel preferences, block lists, and
 * dispatch observability apply per recipient; NotificationService is
 * error-resilient, so one recipient's dispatch failure never blocks the
 * remaining sends or the status write itself.
 *
 * Tables (Games linked via games.event_id) are lifecycle-neutral
 * (M063/S05, R059): cancelling an event — or completing it — never
 * touches its tables. The link survives the transition, and each
 * table stays a fully working game with its own status, seats, and
 * participants; a host who wants their session standalone detaches it
 * themselves from the game's own manage surface. New tables stop being
 * accepted (Event::canHostTables gates the CTA and the CreateGame
 * attach), but existing ones are never modified or destroyed here.
 * The link is only ever detached by explicit event deletion, enforced
 * at the database level (FK nullOnDelete) — not by this service.
 */
class EventLifecycleService
{
    /**
     * Cancel the event and notify every active registrant.
     *
     * No-op when the event is already cancelled. Transition policy
     * (Event::isValidStatusTransition) is enforced by the calling
     * surfaces where user feedback is needed; this service only owns
     * the shared side effect.
     */
    public function cancel(Event $event): void
    {
        if ($event->status === EventStatus::Cancelled) {
            Log::info('event.cancel_skipped_already_cancelled', [
                'event_id' => $event->id,
            ]);

            return;
        }

        $event->update(['status' => EventStatus::Cancelled->value]);

        $this->notifyActiveRegistrants($event);
    }

    /**
     * Dispatch EventCancelled to every user holding an active (not
     * cancelled) registration for the event.
     *
     * @return int the number of recipients the notification was dispatched to
     */
    protected function notifyActiveRegistrants(Event $event): int
    {
        $recipients = User::query()
            ->whereHas('eventRegistrations', fn ($query) => $query
                ->whereBelongsTo($event)
                ->whereNotIn('status', ['cancelled']))
            ->get();

        foreach ($recipients as $recipient) {
            app(NotificationService::class)->send(
                $recipient,
                new EventCancelled($event),
                NotificationCategory::EventRegistration,
            );
        }

        Log::info('event.cancel_notifications_dispatched', [
            'event_id' => $event->id,
            'recipients' => $recipients->count(),
        ]);

        return $recipients->count();
    }

    /**
     * Publish an announcement (idempotent write) without dispatching
     * directly — the EventAnnouncementObserver reacts to the actual
     * is_published transition and routes the notification side effect
     * through announcePublication(), so the Livewire quick-action and
     * every other write surface stay symmetric.
     */
    public function publishAnnouncement(EventAnnouncement $announcement): void
    {
        if ($announcement->is_published) {
            Log::info('event.announcement_publish_skipped_already_published', [
                'announcement_id' => $announcement->id,
                'event_id' => $announcement->event_id,
            ]);

            return;
        }

        $announcement->update(['is_published' => true]);
    }

    /**
     * Dispatch EventAnnouncementPublished for an announcement that just
     * transitioned to published (called by EventAnnouncementObserver).
     *
     * Only VISIBILITY_REGISTERED announcements notify: "all"-tier posts
     * are already public on the event detail page (broadcasting them to
     * every registrant would be noise), and "private" is organizer-only.
     * Recipients are users with an active registration (status !=
     * cancelled), matching the cancellation semantics above. Dispatch
     * goes through NotificationService, so one recipient's failure never
     * blocks the remaining sends.
     */
    public function announcePublication(EventAnnouncement $announcement): void
    {
        if ($announcement->visibility !== EventAnnouncement::VISIBILITY_REGISTERED) {
            Log::info('event.announcement_notifications_skipped_visibility', [
                'announcement_id' => $announcement->id,
                'event_id' => $announcement->event_id,
                'visibility' => $announcement->visibility,
            ]);

            return;
        }

        $event = $announcement->loadMissing(['event', 'author'])->event;

        if (! $event instanceof Event) {
            Log::error('event.announcement_notifications_skipped_missing_event', [
                'announcement_id' => $announcement->id,
                'event_id' => $announcement->event_id,
            ]);

            return;
        }

        $recipients = User::query()
            ->whereHas('eventRegistrations', fn ($query) => $query
                ->where('event_id', $announcement->event_id)
                ->whereNotIn('status', ['cancelled']))
            ->get();

        foreach ($recipients as $recipient) {
            app(NotificationService::class)->send(
                $recipient,
                new EventAnnouncementPublished($announcement, $event),
                NotificationCategory::EventRegistration,
            );
        }

        Log::info('event.announcement_notifications_dispatched', [
            'announcement_id' => $announcement->id,
            'event_id' => $announcement->event_id,
            'recipients' => $recipients->count(),
        ]);
    }
}
