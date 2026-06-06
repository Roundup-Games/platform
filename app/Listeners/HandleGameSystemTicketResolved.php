<?php

namespace App\Listeners;

use App\Enums\NotificationCategory;
use App\Models\GameSystem;
use App\Notifications\GameSystemRequestApproved;
use App\Services\GameSystemRequestService;
use App\Services\NotificationService;
use Escalated\Laravel\Events\TicketResolved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
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
 *
 * Implements ShouldQueue so the BGG sync + DB writes run asynchronously via Horizon.
 */
class HandleGameSystemTicketResolved implements ShouldQueue
{
    use InteractsWithQueue;
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

        $metadata = $ticket->metadata ?? [];

        // If a GameSystem was already created via admin action (Sync from BGG / Create Manually),
        // the admin also resolved the ticket manually — just send the notification.
        $existingGameSystemId = $metadata['game_system_id'] ?? null;

        if ($existingGameSystemId) {
            $gameSystem = GameSystem::find($existingGameSystemId);

            if ($gameSystem) {
                Log::info('Game system already exists from admin action — sending notification only', [
                    'ticket_id' => $ticket->id,
                    'game_system_id' => $gameSystem->id,
                    'game_system_name' => $gameSystem->name,
                ]);

                $this->notifyRequester($ticket, $gameSystem);

                return;
            }
        }

        Log::info('Game system ticket resolved — processing approval', [
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'has_subject' => filled($ticket->subject),
        ]);

        try {
            $bggUrl = $metadata['bgg_url'] ?? null;
            $bggSynced = false;

            // Create GameSystem — sync from BGG if bgg_url is available, otherwise manual
            if ($bggUrl) {
                try {
                    $gameSystem = $service->syncBggFromTicket($ticket);
                    $bggSynced = true;
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    // BGG sync failed — fall back to manual creation
                    Log::info('BGG sync failed, falling back to manual creation', [
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
                'bgg_synced' => $bggSynced,
            ]);

            // Notify the requester
            $this->notifyRequester($ticket, $gameSystem);

        } catch (\Throwable $e) {
            Log::error('Game system ticket approval processing failed', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw so the event dispatcher knows the listener failed.
            // The ticket will remain in its current status for manual review.
            throw $e;
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
