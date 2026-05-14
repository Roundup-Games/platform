<?php

namespace App\Listeners;

use App\Enums\NotificationCategory;
use App\Models\GameSystem;
use App\Notifications\GameSystemRequestApproved;
use App\Services\BggSyncService;
use App\Services\NotificationService;
use Escalated\Laravel\Events\TicketResolved;
use Escalated\Laravel\Models\Department;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

        if (! $this->isGameSystemRequest($ticket)) {
            return;
        }

        Log::info('Game system ticket resolved — processing approval', [
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'subject' => $ticket->subject,
        ]);

        try {
            $metadata = $ticket->metadata ?? [];
            $name = $this->extractName($ticket);
            $bggUrl = $metadata['bgg_url'] ?? null;
            $type = $metadata['game_system_type'] ?? 'boardgame';

            // Create GameSystem — sync from BGG if bgg_url is available
            $gameSystem = $this->createGameSystem($name, $type, $bggUrl, $metadata, $ticket);

            // Update ticket metadata with game_system_id
            $metadata['game_system_id'] = $gameSystem->id;
            $ticket->updateQuietly(['metadata' => $metadata]);

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
     * Check if this ticket is a game system request in the Game Systems department.
     */
    protected function isGameSystemRequest($ticket): bool
    {
        if (($ticket->ticket_type ?? null) !== 'game_system_request') {
            return false;
        }

        $department = $ticket->department;

        return $department && $department->name === 'Game Systems';
    }

    /**
     * Extract the game system name from the ticket subject.
     * Subject format: "Game System Request: {name}"
     */
    protected function extractName($ticket): string
    {
        $subject = $ticket->subject ?? '';

        if (str_starts_with($subject, 'Game System Request: ')) {
            return trim(Str::after($subject, 'Game System Request: '));
        }

        return trim($subject);
    }

    /**
     * Create a GameSystem — either synced from BGG or manually from request data.
     */
    protected function createGameSystem(string $name, string $type, ?string $bggUrl, array $metadata, $ticket): GameSystem
    {
        if ($bggUrl) {
            $bggId = $this->extractBggId($bggUrl);

            if ($bggId) {
                return $this->syncFromBgg($bggId);
            }
        }

        // Manual creation from request data
        return GameSystem::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $ticket->description ?? '',
            'type' => $type,
            'year_released' => null,
            'source' => 'manual',
        ]);
    }

    /**
     * Extract BGG ID from a BGG URL.
     * Supports formats like:
     * - https://boardgamegeek.com/boardgame/12345/ticket-to-ride
     * - https://boardgamegeek.com/boardgame/12345
     * - https://boardgamegeek.com/boardgameexpansion/12345/...
     */
    protected function extractBggId(string $url): ?int
    {
        // Match /boardgame/ID or /boardgameexpansion/ID or /boardgameaccessory/ID
        if (preg_match('#boardgamegeek\.com/(?:boardgame(?:expansion|accessory)?)/(\d+)#i', $url, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Sync a GameSystem from BGG using BggSyncService.
     */
    protected function syncFromBgg(int $bggId): GameSystem
    {
        $result = app(BggSyncService::class)->syncGameSystems([$bggId]);

        if ($result['failed'] > 0 && $result['synced'] === 0) {
            throw new \RuntimeException(
                'BGG sync failed: ' . implode('; ', $result['errors'])
            );
        }

        $gameSystem = GameSystem::where('bgg_id', $bggId)->first();

        if (! $gameSystem) {
            throw new \RuntimeException("BGG sync completed but GameSystem not found for bgg_id={$bggId}.");
        }

        return $gameSystem;
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
