<?php

namespace App\Listeners;

use App\Services\AttendanceService;
use Escalated\Laravel\Events\TicketResolved;
use Illuminate\Support\Facades\Log;

/**
 * Handles attendance dispute tickets that are resolved by staff.
 *
 * When a ticket in the Events department with ticket_type=attendance_dispute
 * is resolved, this listener delegates to AttendanceService::resolveDisputeFromTicket()
 * to apply the resolution to the underlying attendance dispute and notify the user.
 */
class HandleAttendanceDisputeTicketResolved
{
    /**
     * Handle the event.
     */
    public function handle(TicketResolved $event): void
    {
        $ticket = $event->ticket;
        $metadata = $ticket->metadata ?? [];

        // Only handle attendance dispute tickets
        if (($ticket->ticket_type ?? null) !== 'attendance_dispute') {
            return;
        }

        if (($metadata['attendance_dispute'] ?? false) !== true) {
            return;
        }

        Log::info('Attendance dispute ticket resolved — applying dispute resolution', [
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'participant_id' => $metadata['participant_id'] ?? null,
            'game_id' => $metadata['game_id'] ?? null,
        ]);

        try {
            app(AttendanceService::class)->resolveDisputeFromTicket($ticket);
        } catch (\Throwable $e) {
            Log::error('Failed to resolve attendance dispute from ticket', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
