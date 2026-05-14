<?php

namespace App\Services;

use App\Models\User;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Illuminate\Support\Facades\Log;

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
     * @return Ticket The created ticket
     */
    public function createRequest(User $user, array $data): Ticket
    {
        $department = Department::where('name', 'Game Systems')->first();

        $metadata = [
            'game_system_request' => true,
            'bgg_url' => $data['bgg_url'] ?? null,
            'publisher' => $data['publisher'] ?? null,
            'designer' => $data['designer'] ?? null,
            'game_system_type' => $data['type'] ?? null,
            'game_system_id' => null,
        ];

        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $user->id,
            'subject' => 'Game System Request: ' . trim($data['name']),
            'description' => $data['notes'] ?? '',
            'status' => TicketStatus::Open->value,
            'priority' => TicketPriority::Medium->value,
            'department_id' => $department?->id,
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
            'name' => trim($data['name']),
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

        return Ticket::where('requester_type', User::class)
            ->where('requester_id', $user->id)
            ->where('ticket_type', 'game_system_request')
            ->whereRaw('LOWER(subject) LIKE ?', ['%game system request: ' . $normalizedName])
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value])
            ->exists();
    }

    /**
     * Apply the bgg-sync tag to the ticket.
     */
    protected function applyBggSyncTag(Ticket $ticket): void
    {
        $tag = Tag::where('name', 'bgg-sync')->first();

        if ($tag) {
            $ticket->tags()->syncWithoutDetaching([$tag->id]);
        }
    }
}
