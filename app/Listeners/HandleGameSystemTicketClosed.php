<?php

namespace App\Listeners;

use App\Enums\NotificationCategory;
use App\Models\GameSystem;
use App\Notifications\GameSystemRequestDuplicate;
use App\Notifications\GameSystemRequestRejected;
use App\Services\NotificationService;
use Escalated\Laravel\Events\TicketClosed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Handles game system request tickets that are closed (rejected or duplicate).
 *
 * When a ticket in the Game Systems department with ticket_type=game_system_request
 * is closed, this listener determines whether it's a rejection or duplicate:
 * - Duplicate: metadata contains duplicate_of_game_system_id → send duplicate notification
 * - Rejection: default → extract rejection reason from latest internal note, send rejection notification
 *
 * Implements ShouldQueue so notification dispatch runs asynchronously via Horizon.
 */
class HandleGameSystemTicketClosed implements ShouldQueue
{
    use InteractsWithQueue;
    /**
     * Handle the event.
     */
    public function handle(TicketClosed $event): void
    {
        $ticket = $event->ticket;
        $service = app(\App\Services\GameSystemRequestService::class);

        if (! $service->isGameSystemRequestTicket($ticket)) {
            return;
        }

        $metadata = $ticket->metadata ?? [];

        if (isset($metadata['duplicate_of_game_system_id'])) {
            $this->handleDuplicate($ticket, $metadata);
        } else {
            $this->handleRejection($ticket);
        }
    }

    /**
     * Handle duplicate flow: notify requester about the existing game system.
     */
    protected function handleDuplicate($ticket, array $metadata): void
    {
        $existingSystemId = $metadata['duplicate_of_game_system_id'];
        $existingSystem = GameSystem::find($existingSystemId);

        if (! $existingSystem) {
            Log::error('Duplicate game system not found for ticket', [
                'ticket_id' => $ticket->id,
                'duplicate_of_game_system_id' => $existingSystemId,
            ]);

            return;
        }

        Log::info('Game system ticket closed as duplicate', [
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'existing_game_system_id' => $existingSystem->id,
            'existing_game_system_name' => $existingSystem->name,
        ]);

        $this->notifyRequester($ticket, fn ($requester) => new GameSystemRequestDuplicate(
            $ticket,
            $existingSystem,
        ));
    }

    /**
     * Handle rejection flow: extract rejection reason from internal note, notify requester.
     */
    protected function handleRejection($ticket): void
    {
        $rejectionReason = $this->extractRejectionReason($ticket);

        // Store rejection reason in metadata for notification access
        $metadata = $ticket->metadata ?? [];
        $metadata['rejection_reason'] = $rejectionReason;
        $ticket->updateQuietly(['metadata' => $metadata]);

        Log::info('Game system ticket closed as rejected', [
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'rejection_reason' => $rejectionReason,
        ]);

        $this->notifyRequester($ticket, fn ($requester) => new GameSystemRequestRejected(
            $ticket,
        ));
    }

    /**
     * Extract rejection reason from the latest internal note on the ticket.
     */
    protected function extractRejectionReason($ticket): ?string
    {
        // Load internal notes if not already loaded
        $latestNote = $ticket->internalNotes()
            ->latest()
            ->first();

        return $latestNote?->body;
    }

    /**
     * Send notification to the ticket requester.
     */
    protected function notifyRequester($ticket, callable $notificationFactory): void
    {
        $requester = $ticket->requester;

        if (! $requester) {
            Log::warning('Cannot send notification — no requester on ticket', [
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        try {
            app(NotificationService::class)->send(
                $requester,
                $notificationFactory($requester),
                NotificationCategory::GameSystemRequest,
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send game system request notification', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
