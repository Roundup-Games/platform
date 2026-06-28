<?php

namespace App\Services;

use App\Enums\VenueType;
use App\Models\Location;
use App\Models\User;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\Log;

/**
 * Creates and manages venue proposal tickets in Escalated.
 *
 * When an authenticated user proposes a new venue, this service creates an
 * Escalated ticket with ticket_type = 'venue_proposal' in the Events department.
 * The ticket metadata stores the proposed venue details (name, address, type,
 * website, notes) so admins can review and approve directly into a verified
 * Location.
 *
 * === Key design decisions ===
 *
 * - ticket_type (string column) is used for classification, NOT a category enum.
 *   The Escalated package has no TicketCategory enum — types are free-form strings.
 * - Tags provide additional filtering (venue-proposal tag).
 * - The User model already implements the Ticketable contract.
 * - Tickets require: subject, description, status, priority, department_id,
 *   channel, requester_type/id.  The metadata (json) column stores arbitrary data.
 * - The Events department is reused for venue proposals since venues are
 *   event-location infrastructure.
 */
class VenueProposalService
{
    /**
     * Create a venue proposal as an Escalated ticket.
     *
     * @param  User  $user  The authenticated user proposing the venue
     * @param  array  $data  Validated proposal data: name, address, venue_type, website_url?, notes?
     * @param  array<string, mixed>  $data
     * @return Ticket The created ticket
     *
     * @throws \RuntimeException When the Events department is not configured
     */
    public function createProposal(User $user, array $data): Ticket
    {
        $department = Department::where('name', 'Events')->first();

        if (! $department) {
            Log::error('venue_proposal.events_department_missing');
            throw new \RuntimeException('Events department is not configured.');
        }

        $venueType = $data['venue_type'] ?? null;
        $venueTypeLabel = $venueType instanceof VenueType
            ? $venueType->label()
            : ($venueType ? VenueType::tryFrom(is_int($venueType) || is_string($venueType) ? $venueType : '')?->label() ?? (is_string($venueType) || is_int($venueType) ? (string) $venueType : '') : null);

        $metadata = $this->buildMetadata($user, $data, $venueType instanceof VenueType ? $venueType->value : (is_string($venueType) ? $venueType : null));

        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $user->id,
            'subject' => 'Venue Proposal: '.trim(is_string($dn = $data['name'] ?? '') ? $dn : ''),
            'description' => $this->buildDescription($data, is_string($venueTypeLabel) ? $venueTypeLabel : null),
            'status' => TicketStatus::Open->value,
            'priority' => TicketPriority::Medium->value,
            'department_id' => $department->id,
            'ticket_type' => 'venue_proposal',
            'channel' => TicketChannel::Web->value,
            'metadata' => $metadata,
        ]);

        $this->applyVenueProposalTag($ticket);

        Log::info('venue_proposal.submitted', [
            'user_id' => $user->id,
            'venue_name' => trim(is_string($dn = $data['name'] ?? '') ? $dn : ''),
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
        ]);

        return $ticket;
    }

    /**
     * Approve a venue proposal by creating or updating a Location with is_verified=true.
     *
     * If a Location with the same name and address already exists, it is updated.
     * Otherwise, a new verified Location is created.
     *
     * @param  Ticket  $ticket  The venue proposal ticket
     * @return Location The created or updated verified Location
     *
     * @throws \InvalidArgumentException When the ticket is not a venue proposal
     */
    public function approveProposal(Ticket $ticket): Location
    {
        if (! $this->isVenueProposalTicket($ticket)) {
            throw new \InvalidArgumentException('Ticket is not a venue proposal.');
        }

        $metadata = $ticket->metadata ?? [];

        $payload = [
            'is_verified' => true,
            'venue_type' => $metadata['venue_type'] ?? null,
            'website_url' => $metadata['website_url'] ?? null,
            'venue_notes' => $metadata['notes'] ?? $metadata['proposer_notes'] ?? null,
            'source' => 'venue_proposal',
            'city' => $metadata['venue_city'] ?? null,
            'postal_code' => $metadata['venue_postal_code'] ?? null,
            'country' => $metadata['venue_country'] ?? null,
            'latitude' => $metadata['latitude'] ?? null,
            'longitude' => $metadata['longitude'] ?? null,
            'venue_metadata' => array_merge(
                is_array($metadata['venue_metadata'] ?? null) ? $metadata['venue_metadata'] : [],
                [
                    'approved_from_ticket' => $ticket->reference,
                    'proposed_by_user_id' => is_array($metadata['actor'] ?? null) ? ($metadata['actor']['id'] ?? null) : null,
                    'geocoded_display_name' => $metadata['geocoded_display_name'] ?? null,
                ]
            ),
        ];

        $existingId = $metadata['existing_location_id'] ?? null;
        if (is_string($existingId) && $existingId !== '' && $existing = Location::find($existingId)) {
            $existing->update($payload);
            $location = $existing;
        } else {
            $location = Location::updateOrCreate(
                [
                    'name' => trim(is_string($vn = $metadata['venue_name'] ?? $this->extractName($ticket)) ? $vn : ''),
                    'address' => $metadata['venue_address'] ?? '',
                ],
                $payload
            );
        }

        // Update ticket metadata to record the linked location
        $metadata['location_id'] = $location->id;
        $ticket->updateQuietly(['metadata' => $metadata]);

        // Attach the created/updated Location as the ticket subject now that
        // it exists (deferred link — the proposal ticket predates the venue).
        $ticket->attachSubject($location, 'venue');

        Log::info('venue_proposal.approved', [
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'location_id' => $location->id,
            'location_name' => $location->name,
        ]);

        return $location;
    }

    /**
     * Check if a user already has a pending venue proposal for the same name.
     */
    public function hasPendingProposal(User $user, string $name): bool
    {
        $normalizedName = mb_strtolower(trim($name));
        $escapedName = str_replace(['%', '_'], ['\\%', '\\_'], $normalizedName);

        return Ticket::where('requester_type', User::class)
            ->where('requester_id', $user->id)
            ->where('ticket_type', 'venue_proposal')
            ->whereRaw('LOWER(subject) LIKE ?', ['%venue proposal: '.$escapedName])
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value])
            ->exists();
    }

    /**
     * Check if a ticket is a venue proposal in the Events department.
     */
    public function isVenueProposalTicket(Ticket $ticket): bool
    {
        if (($ticket->ticket_type ?? null) !== 'venue_proposal') {
            return false;
        }

        $department = $ticket->department;

        return $department && $department->name === 'Events';
    }

    /**
     * Extract the venue name from the ticket subject.
     * Subject format: "Venue Proposal: {name}"
     */
    public function extractName(Ticket $ticket): string
    {
        $subject = $ticket->subject ?? '';

        if (str_starts_with($subject, 'Venue Proposal: ')) {
            return trim(substr($subject, strlen('Venue Proposal: ')));
        }

        return trim($subject);
    }

    /**
     * Build the structured metadata payload for the ticket.
     *
     * Follows the TicketPayloadRenderer schema convention:
     *   schema, actor, action, entities, reason, details, context
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function buildMetadata(User $user, array $data, ?string $venueType): array
    {
        return [
            'schema' => 'venue_proposal/v1',
            'actor' => ['type' => 'user', 'id' => $user->id, 'name' => $user->name],
            'action' => 'request',
            'entities' => [],
            'reason' => 'venue_proposal',
            'details' => $data['proposer_notes'] ?? $data['notes'] ?? null,
            // Flat keys for venue proposal data
            'venue_name' => trim(is_string($dn = $data['name'] ?? '') ? $dn : ''),
            'venue_address' => $data['address'] ?? null,
            'venue_city' => $data['city'] ?? null,
            'venue_postal_code' => $data['postal_code'] ?? null,
            'venue_country' => $data['country'] ?? null,
            'venue_type' => $venueType,
            'website_url' => $data['website_url'] ?? null,
            'proposer_notes' => $data['proposer_notes'] ?? null,
            'admin_notes' => $data['admin_notes'] ?? null,
            'notes' => $data['notes'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'geocoded_display_name' => $data['geocoded_display_name'] ?? null,
            'existing_location_id' => $data['existing_location_id'] ?? null,
            'location_id' => null, // Populated on approval
        ];
    }

    /**
     * Build a human-readable description for the venue proposal ticket.
     *
     * @param  array<string, mixed>  $data
     */
    protected function buildDescription(array $data, ?string $venueTypeLabel): string
    {
        $lines = [
            '**Venue Name:** '.trim(is_string($dn = $data['name'] ?? '') ? $dn : ''),
            '**Address:** '.(is_string($a = $data['address'] ?? 'N/A') ? $a : 'N/A'),
        ];

        if (! empty($data['city']) && is_string($data['city'])) {
            $lines[] = '**City:** '.$data['city'];
        }

        if (! empty($data['postal_code']) && is_string($data['postal_code'])) {
            $lines[] = '**Postal Code:** '.$data['postal_code'];
        }

        if (! empty($data['country']) && is_string($data['country'])) {
            $lines[] = '**Country:** '.$data['country'];
        }

        if ($venueTypeLabel) {
            $lines[] = '**Venue Type:** '.$venueTypeLabel;
        }

        if (! empty($data['website_url']) && is_string($data['website_url'])) {
            $lines[] = '**Website:** '.$data['website_url'];
        }

        if (! empty($data['existing_location_id']) && (is_string($data['existing_location_id']))) {
            $lines[] = '**Existing Location:** Yes (ID: '.$data['existing_location_id'].')';
        }

        $lines[] = '';

        if (! empty($data['proposer_notes'])) {
            $lines[] = '**Why this venue:**';
            $lines[] = $data['proposer_notes'];
        }

        if (! empty($data['admin_notes'])) {
            $lines[] = '**Admin notes:**';
            $lines[] = $data['admin_notes'];
        }

        if (empty($data['proposer_notes']) && empty($data['admin_notes'])) {
            $lines[] = '**Notes:**';
            $lines[] = 'No additional notes provided.';
        }

        return implode("\n", array_map(fn (mixed $l) => is_string($l) ? $l : '', $lines));
    }

    /**
     * Apply the venue-proposal tag to the ticket.
     */
    protected function applyVenueProposalTag(Ticket $ticket): void
    {
        $tag = Tag::firstOrCreate(
            ['name' => 'venue-proposal'],
            ['color' => '#10B981']
        );

        $ticket->tags()->syncWithoutDetaching([$tag->id]);
    }
}
