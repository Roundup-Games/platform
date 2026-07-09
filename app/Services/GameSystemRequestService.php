<?php

namespace App\Services;

use App\Models\GameSystem;
use App\Models\User;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Creates and manages game system requests as Escalated tickets.
 *
 * Replaces the legacy GameSystemRequest model with Escalated Ticket + metadata.
 * Custom field values (bgg_url, publisher, designer, game_system_type, game_system_id)
 * are stored in the ticket's metadata JSON because the custom_field_values.entity_id
 * column is UUID type and Ticket uses auto-increment bigint IDs.
 */
class GameSystemRequestService
{
    /**
     * Create a new game system request as an Escalated ticket.
     *
     * @param  User  $user  The authenticated user making the request
     * @param  array  $data  Validated request data: name, type, bgg_url?, publisher?, designer?, notes?
     * @param  array<string, mixed>  $data
     * @return Ticket The created ticket
     */
    public function createRequest(User $user, array $data): Ticket
    {
        $department = Department::where('name', 'Game Systems')->firstOrFail();

        $name = is_string($data['name'] ?? null) ? $data['name'] : '';
        $bggUrl = is_string($data['bgg_url'] ?? null) ? $data['bgg_url'] : null;
        $publisher = is_string($data['publisher'] ?? null) ? $data['publisher'] : null;
        $designer = is_string($data['designer'] ?? null) ? $data['designer'] : null;
        $type = is_string($data['type'] ?? null) ? $data['type'] : null;
        $notes = is_string($data['notes'] ?? null) ? $data['notes'] : null;

        $metadata = TicketPayloadRenderer::gameSystemRequestPayload(
            user: $user,
            name: trim($name),
            bggUrl: $bggUrl,
            publisher: $publisher,
            designer: $designer,
            type: $type,
            notes: $notes,
        );

        $ticket = $user->escalatedTickets()->create([
            'subject' => 'Game System Request: '.trim($name),
            'description' => is_string($data['notes'] ?? null) ? $data['notes'] : '',
            'status' => TicketStatus::Open->value,
            'priority' => TicketPriority::Medium->value,
            'department_id' => $department->id,
            'ticket_type' => 'game_system_request',
            'channel' => TicketChannel::Web->value,
            'metadata' => $metadata,
        ]);

        // Apply bgg-sync tag if bgg_url is provided
        if (! empty($data['bgg_url'])) {
            $this->applyBggSyncTag($ticket);
        }

        Log::info('Game system request submitted', [
            'user_id' => $user->id,
            'name' => trim($name),
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
        ]);

        return $ticket;
    }

    /**
     * Check if a user already has a pending/in-progress game system request for the given name.
     */
    public function hasPendingRequest(User $user, string $name): bool
    {
        $normalizedName = mb_strtolower(trim($name));

        // Escape LIKE wildcards to prevent false duplicate matches
        $escapedName = str_replace(['%', '_'], ['\\%', '\\_'], $normalizedName);

        return Ticket::where('requester_type', User::class)
            ->where('requester_id', $user->id)
            ->where('ticket_type', 'game_system_request')
            ->whereRaw('LOWER(subject) LIKE ?', ['%game system request: '.$escapedName])
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value])
            ->exists();
    }

    /**
     * Sync a GameSystem from BGG using the ticket's bgg_url metadata.
     *
     * Extracts the BGG ID from the bgg_url in ticket metadata, calls
     * BggSyncService to sync, and updates the ticket with game_system_id.
     *
     * @return GameSystem The created/updated GameSystem
     *
     * @throws \InvalidArgumentException When ticket is not a game system request or has no valid BGG URL
     * @throws \RuntimeException When BGG sync fails
     */
    public function syncBggFromTicket(Ticket $ticket): GameSystem
    {
        if (! $this->isGameSystemRequestTicket($ticket)) {
            throw new \InvalidArgumentException('Ticket is not a game system request.');
        }

        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $bggUrl = is_string($metadata['bgg_url'] ?? null) ? $metadata['bgg_url'] : null;

        if (! $bggUrl) {
            throw new \InvalidArgumentException('Ticket has no BGG URL in metadata.');
        }

        $bggId = $this->extractBggId($bggUrl);

        if (! $bggId) {
            throw new \InvalidArgumentException("Cannot extract BGG ID from URL: {$bggUrl}");
        }

        Log::info('BGG sync triggered from ticket', [
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'bgg_id' => $bggId,
            'bgg_url' => $bggUrl,
        ]);

        $result = app(BggSyncService::class)->syncGameSystems([$bggId]);

        if ($result->failed > 0 && $result->synced === 0) {
            throw new \RuntimeException(
                'BGG sync failed: '.implode('; ', $result->errors)
            );
        }

        $gameSystem = GameSystem::where('bgg_id', $bggId)->first();

        if (! $gameSystem) {
            throw new \RuntimeException("BGG sync completed but GameSystem not found for bgg_id={$bggId}.");
        }

        // Update ticket metadata with game_system_id
        // Use updateQuietly to avoid re-triggering model events (e.g. ticket listeners).
        $metadata['game_system_id'] = $gameSystem->id;
        $ticket->updateQuietly(['metadata' => $metadata]);

        // Attach the synced GameSystem as the ticket subject now that it exists
        // (deferred link — the request ticket predates the GameSystem).
        $ticket->attachSubject($gameSystem, 'created');

        Log::info('BGG sync from ticket completed', [
            'ticket_id' => $ticket->id,
            'game_system_id' => $gameSystem->id,
            'game_system_name' => $gameSystem->name,
            'bgg_id' => $bggId,
        ]);

        return $gameSystem;
    }

    /**
     * Create a GameSystem manually from ticket metadata (no BGG sync).
     *
     * @return GameSystem The created GameSystem
     */
    public function createManualFromTicket(Ticket $ticket): GameSystem
    {
        if (! $this->isGameSystemRequestTicket($ticket)) {
            throw new \InvalidArgumentException('Ticket is not a game system request.');
        }

        $metadata = $ticket->metadata ?? [];
        $name = $this->extractName($ticket);
        $type = $metadata['game_system_type'] ?? 'boardgame';

        return DB::transaction(function () use ($ticket, $metadata, $name, $type) {
            $gameSystem = GameSystem::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $ticket->description ?? '',
                'type' => $type,
                'year_released' => null,
                'source' => 'manual',
            ]);

            // Update ticket metadata with game_system_id
            // Use updateQuietly to avoid re-triggering model events (e.g. ticket listeners).
            $metadata['game_system_id'] = $gameSystem->id;
            $ticket->updateQuietly(['metadata' => $metadata]);

            // Attach the manually-created GameSystem as the ticket subject
            // (deferred link — the request ticket predates the GameSystem).
            $ticket->attachSubject($gameSystem, 'created');

            Log::info('Manual GameSystem created from ticket', [
                'ticket_id' => $ticket->id,
                'game_system_id' => $gameSystem->id,
                'game_system_name' => $gameSystem->name,
            ]);

            return $gameSystem;
        });
    }

    /**
     * Check if a ticket is a game system request in the Game Systems department.
     */
    public function isGameSystemRequestTicket(Ticket $ticket): bool
    {
        if (($ticket->ticket_type ?? null) !== 'game_system_request') {
            return false;
        }

        $department = $ticket->department;

        return $department && $department->name === 'Game Systems';
    }

    /**
     * Extract BGG ID from a BGG URL.
     * Supports formats like:
     * - https://boardgamegeek.com/boardgame/12345/ticket-to-ride
     * - https://boardgamegeek.com/boardgame/12345
     * - https://boardgamegeek.com/boardgameexpansion/12345/...
     */
    public function extractBggId(string $url): ?int
    {
        if (preg_match('#boardgamegeek\.com/(?:boardgame(?:expansion|accessory)?)/(\d+)#i', $url, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Extract the game system name from the ticket subject.
     * Subject format: "Game System Request: {name}"
     */
    public function extractName(Ticket $ticket): string
    {
        $subject = $ticket->subject ?? '';

        if (str_starts_with($subject, 'Game System Request: ')) {
            return trim(Str::after($subject, 'Game System Request: '));
        }

        return trim($subject);
    }

    /**
     * Apply the bgg-sync tag to the ticket.
     */
    protected function applyBggSyncTag(Ticket $ticket): void
    {
        $tag = Tag::where('name', 'bgg-sync')->first();

        if ($tag) {
            $ticket->tags()->syncWithoutDetaching($tag);
        }
    }
}
