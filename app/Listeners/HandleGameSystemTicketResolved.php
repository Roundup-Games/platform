<?php

namespace App\Listeners;

use App\Enums\NotificationCategory;
use App\Models\GameSystem;
use App\Notifications\GameSystemRequestApproved;
use App\Services\GameSystemRequestService;
use App\Services\NotificationService;
use Escalated\Laravel\Events\TicketResolved;
use Illuminate\Support\Facades\Log;

/**
 * Handles game system request tickets that are resolved (approved).
 *
 * When a ticket in the Game Systems department with ticket_type=game_system_request
 * is resolved, this listener:
 * 1. Parses metadata for name, type, bgg_url, publisher, designer
 * 2. Creates a GameSystem (syncing from BGG if bgg_url is present)
 * 3. Updates ticket metadata with game_system_id
 * 4. Sends GameSystemRequestApproved notification to the requester
 */
class HandleGameSystemTicketResolved
{
    /**
     * Handle the event.
     */
    public function handle(TicketResolved $event): void
    {
        $ticket = $event->ticket;
        $service = app(GameSystemRequestService::class);

        if (! $service->isGameSystemRequestTicket($ticket)) {
            return;
        }

        Log::info('Game system ticket resolved — processing approval', [
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'subject' => $ticket->subject,
        ]);

        try {
            $metadata = $ticket->metadata ?? [];
            $bggUrl = $metadata['bgg_url'] ?? null;

            // Create GameSystem — sync from BGG if bgg_url is available, otherwise manual
            if ($bggUrl) {
                try {
                    $gameSystem = $service->syncBggFromTicket($ticket);
                } catch (\InvalidArgumentException $e) {
                    // BGG URL present but couldn't extract ID — fall back to manual
                    Log::info('BGG URL present but could not extract ID, falling back to manual creation', [
                        'ticket_id' => $ticket->id,
                        'bgg_url' => $bggUrl,
                        'error' => $e->getMessage(),
                    ]);
                    $gameSystem = $service->createManualFromTicket($ticket);
                }
            } else {
                $gameSystem = $service->createManualFromTicket($ticket);
            }

            Log::info('Game system created from ticket approval', [
                'ticket_id' => $ticket->id,
                'game_system_id' => $gameSystem->id,
                'game_system_name' => $gameSystem->name,
                'bgg_synced' => $bggUrl !== null,
            ]);

            // Notify the requester
            $this->notifyRequester($ticket, $gameSystem);

        } catch (\Throwable $e) {
            Log::error('Game system ticket approval processing failed', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Send GameSystemRequestApproved notification to the ticket requester.
     */
    protected function notifyRequester($ticket, GameSystem $gameSystem): void
    {
        $requester = $ticket->requester;

        if (! $requester) {
            Log::warning('Cannot send approval notification — no requester on ticket', [
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        try {
            app(NotificationService::class)->send(
                $requester,
                new GameSystemRequestApproved($ticket, $gameSystem),
                NotificationCategory::GameSystemRequest,
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send GameSystemRequestApproved notification', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
