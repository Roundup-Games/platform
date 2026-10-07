<?php

namespace App\Observers;

use App\Models\EventAnnouncement;
use App\Services\EventLifecycleService;

/**
 * The single transition hook for announcement publication (M063/S03).
 *
 * The publish side effect — notifying active registrants — must fire
 * exactly once per false → true is_published transition regardless of
 * which surface performed the write:
 *
 *   - EventAnnouncements::publishAnnouncement() (organizer quick action,
 *     routed through EventLifecycleService::publishAnnouncement())
 *   - the is_published toggle in the Livewire edit form (save())
 *   - the Filament AnnouncementsRelationManager edit form
 *   - direct-published creates (Livewire create form, Filament CreateAction)
 *
 * The wasChanged('is_published') guard on updated() provides the
 * terminal-once semantics: re-publishing an already-published
 * announcement is not a change and never notifies, while unpublish →
 * republish is a genuine new publication and notifies again. Dispatch
 * itself (tier check, recipient query, NotificationService fan-out) is
 * owned by EventLifecycleService::announcePublication(), keeping the
 * observer a pure transition detector.
 */
class EventAnnouncementObserver
{
    public function __construct(
        private EventLifecycleService $lifecycle,
    ) {}

    /**
     * Announcements created directly in the published state count as a
     * publication transition (wasChanged() is unavailable on create).
     */
    public function created(EventAnnouncement $announcement): void
    {
        if ($announcement->is_published) {
            $this->lifecycle->announcePublication($announcement);
        }
    }

    public function updated(EventAnnouncement $announcement): void
    {
        if ($announcement->wasChanged('is_published') && $announcement->is_published) {
            $this->lifecycle->announcePublication($announcement);
        }
    }
}
