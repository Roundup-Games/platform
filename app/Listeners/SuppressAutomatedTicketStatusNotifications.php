<?php

namespace App\Listeners;

use Escalated\Laravel\Enums\ActivityType;
use Escalated\Laravel\Models\TicketActivity;
use Escalated\Laravel\Notifications\TicketStatusChangedNotification;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * Suppresses customer-facing "Status Updated" notifications when a ticket's
 * status change was system-initiated (automated) rather than performed by a
 * human.
 *
 * The Escalated package's SendStatusChangeNotification listener fires on every
 * TicketStatusChanged event and notifies the requester + followers — including
 * automated transitions from scheduled jobs. The worst offender is the nightly
 * `escalated:close-resolved` job, which archives resolved tickets (resolved →
 * closed) and emails every customer "Status Updated: closed" at ~02:00 UTC.
 * Escalation-rule status changes are likewise automated.
 *
 * Those transitions have no human causer and should not notify customers.
 *
 * Implementation note: TicketStatusChangedNotification is queued (ShouldQueue),
 * so this listener runs on the queue worker, in a fresh process. The causer is
 * not carried on the serialized notification. However, the package's
 * LogTicketStatusChange listener (registered before SendStatusChangeNotification
 * and therefore run earlier in the same event dispatch) records the causer on
 * the ticket's status_changed activity row. By the time the worker processes
 * the queued notification, that row exists — so we read it to decide.
 *
 * Returning false halts the send: Laravel's NotificationSender resolves the
 * NotificationSending event via events->until(), and a false response makes
 * shouldSendNotification() return false for that channel.
 */
class SuppressAutomatedTicketStatusNotifications
{
    public function handle(NotificationSending $event): ?bool
    {
        $notification = $event->notification;

        if (! $notification instanceof TicketStatusChangedNotification) {
            return null;
        }

        $ticket = $notification->ticket ?? null;

        if (! $ticket) {
            return null;
        }

        $causerType = TicketActivity::query()
            ->whereBelongsTo($ticket)
            ->where('type', ActivityType::StatusChanged->value)
            ->latest('id')
            ->value('causer_type');

        // causer_type IS NULL ⇒ system-initiated transition → suppress.
        // Human-initiated changes always carry a causer_type, so they pass through.
        if ($causerType === null) {
            return false;
        }

        return null;
    }
}
