<?php

namespace App\Services;

use App\Models\Location;
use App\Models\User;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\TicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates and manages venue *claim* tickets in Escalated.
 *
 * A venue claim is filed by an operator (an authenticated user) who wants to
 * curate an existing public venue page. This service is a 1:1 mirror of
 * {@see VenueProposalService}: same Events department, same metadata-schema
 * convention, same tag + ticket_type classification mechanism — but with a
 * different `ticket_type` ('venue_claim') and a different approval mutation.
 *
 * Whereas approving a venue *proposal* creates/updates a verified Location,
 * approving a venue *claim* sets the existing Location's `managed_by` to the
 * claimant (and defensively backfills a slug). No new Location is created and
 * no address is ever mutated.
 *
 * === Privacy invariant (MEM717) ===
 *
 * Claim metadata stores the venue NAME and CITY only — never the street
 * address, coordinates, postal code, or geohash. The claim is about
 * stewardship of a page that already exists, not about disclosing a private
 * address.
 *
 * === Assignment ===
 *
 * {@see Ticket::assign()} is UUID-safe as of escalated-laravel v1.4.0
 * (the TicketAssigned event's $agentId is typed int|string). This service
 * does not assign tickets itself — venue claims route through the Events
 * department queue — but no workaround is required if assignment is added.
 */
class VenueClaimService
{
    public function __construct(
        private readonly TicketService $ticketService,
    ) {}

    /**
     * Create a venue claim as an Escalated ticket.
     *
     * @param  User  $claimant  The authenticated operator claiming the venue
     * @param  Location  $location  The existing public venue being claimed
     * @param  array<string, mixed>  $data  Validated claim data: claimant_notes (required), website_url?
     * @return Ticket The created ticket
     *
     * @throws \RuntimeException When the Events department is not configured
     */
    public function createClaim(User $claimant, Location $location, array $data): Ticket
    {
        $department = Department::where('name', 'Events')->first();

        if (! $department) {
            Log::error('venue_claim.events_department_missing');
            throw new \RuntimeException('Events department is not configured.');
        }

        $metadata = $this->buildMetadata($claimant, $location, $data);

        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $claimant->id,
            'subject' => 'Venue Claim: '.trim((string) $location->name),
            'description' => $this->buildDescription($location, $data),
            'status' => TicketStatus::Open->value,
            'priority' => TicketPriority::Medium->value,
            'department_id' => $department->id,
            'ticket_type' => 'venue_claim',
            'channel' => TicketChannel::Web->value,
            'metadata' => $metadata,
        ]);

        // Attach the claimed Location as a first-class ticket subject — a
        // queryable FK link and a model-owned deep link for the admin UI.
        // (hasPendingClaim still matches on metadata->location_id rather than
        // the subject relation, so duplicate detection stays robust against
        // legacy tickets that haven't been backfilled to subjects yet.)
        $ticket->attachSubject($location, 'venue');

        $this->applyVenueClaimTag($ticket);

        Log::info('venue_claim.submitted', [
            'claimant_id' => $claimant->id,
            'location_id' => $location->id,
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
        ]);

        return $ticket;
    }

    /**
     * Approve a venue claim by setting the Location's `managed_by` to the claimant.
     *
     * Runs inside a pessimistic-lock transaction with an open-ticket guard
     * (mirrors `performApproveVenueProposal`'s txn shape). The claimant is read
     * from the ticket metadata. If the location is already managed by someone
     * else, approval is rejected (throws). A slug is defensively generated if
     * the location lacks one (covers the managed-but-unverified edge from T01).
     * The ticket is resolved with an internal note. No Location is created and
     * no address is mutated.
     *
     * @return Location The now-managed Location
     *
     * @throws \InvalidArgumentException When the ticket is not a venue claim
     * @throws \RuntimeException When the ticket is no longer open, the claimant
     *                           is missing, the target location is gone, or the
     *                           venue is already managed by another user
     */
    public function approveClaim(Ticket $ticket): Location
    {
        if (! $this->isVenueClaimTicket($ticket)) {
            throw new \InvalidArgumentException('Ticket is not a venue claim.');
        }

        return DB::transaction(function () use ($ticket): Location {
            $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            if (! $lockedTicket->isOpen()) {
                throw new \RuntimeException('This ticket is no longer open.');
            }

            $metadata = $lockedTicket->metadata ?? [];
            $claimantId = is_array($metadata['actor'] ?? null)
                ? ($metadata['actor']['id'] ?? null)
                : null;

            $claimantId = self::asString($claimantId);
            if ($claimantId === '') {
                throw new \RuntimeException('Venue claim ticket is missing a claimant.');
            }

            $locationId = self::asString($metadata['location_id'] ?? null);
            if ($locationId === '') {
                throw new \RuntimeException('Venue claim ticket is missing a target location.');
            }

            /** @var Location|null $location */
            // Lock the location row for the managed_by check + write so two
            // concurrent admin approvals of the same venue cannot both read
            // managed_by = null and both write (last-write-wins). Held within
            // this transaction until commit, mirroring the ticket lock above.
            $location = Location::lockForUpdate()->find($locationId);
            if (! $location) {
                throw new \RuntimeException('Target location no longer exists.');
            }

            // Already managed by another user → reject approval.
            if ($location->managed_by !== null && (string) $location->managed_by !== $claimantId) {
                throw new \RuntimeException('This venue is already managed by another user.');
            }

            $location->managed_by = $claimantId;

            // Defensive slug backfill for the managed-but-unverified edge (T01).
            if (! $location->slug) {
                $location->slug = Location::generateUniqueSlug((string) $location->name, $location->id);
            }

            $location->save();

            $admin = $this->actingUser();
            $note = "Venue claim approved. {$location->name} (ID: {$location->id}) is now managed by user {$claimantId}.";
            if ($admin) {
                $this->ticketService->addNote($lockedTicket, $admin, $note);
                $this->ticketService->resolve($lockedTicket, $admin);
            } else {
                // Unattended/admin-less path (e.g. service test): resolve via the
                // claimant-free TicketService path using the locked ticket itself.
                $this->ticketService->resolve($lockedTicket);
            }

            Log::info('venue_claim.approved', [
                'ticket_id' => $lockedTicket->id,
                'ticket_reference' => $lockedTicket->reference,
                'location_id' => $location->id,
                'claimant_id' => $claimantId,
            ]);

            return $location;
        });
    }

    /**
     * Reject a venue claim: reply + internal note via TicketService, then
     * resolve the ticket. The Location is NOT mutated.
     */
    public function rejectClaim(Ticket $ticket, string $reason): void
    {
        // Mirror approveClaim's pre-flight guards so a non-claim ticket or an
        // already-resolved ticket cannot be rejected, and concurrent rejects of
        // the same ticket serialize on the row lock. The Location is NOT mutated.
        if (! $this->isVenueClaimTicket($ticket)) {
            throw new \InvalidArgumentException('Ticket is not a venue claim.');
        }

        $lockedTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
        if (! $lockedTicket->isOpen()) {
            throw new \RuntimeException('This ticket is no longer open.');
        }

        $admin = $this->actingUser();
        $body = "Venue claim rejected: {$reason}";

        if ($admin) {
            $this->ticketService->reply($lockedTicket, $admin, $body);
            $this->ticketService->addNote($lockedTicket, $admin, "Venue claim rejected. Reason: {$reason}");
            $this->ticketService->resolve($lockedTicket, $admin);
        } else {
            $this->ticketService->resolve($lockedTicket);
        }

        Log::info('venue_claim.rejected', [
            'ticket_id' => $lockedTicket->id,
            'ticket_reference' => $ticket->reference,
            'reason' => $reason,
        ]);
    }

    /**
     * Guard against a duplicate pending claim by the same user on the same
     * location. Mirrors {@see VenueProposalService::hasPendingProposal()} but
     * matches on the target location_id in metadata instead of the subject.
     */
    public function hasPendingClaim(User $claimant, Location $location): bool
    {
        return Ticket::where('requester_type', User::class)
            ->where('requester_id', $claimant->id)
            ->where('ticket_type', 'venue_claim')
            ->where('metadata->location_id', $location->id)
            ->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value])
            ->exists();
    }

    /**
     * Check if a ticket is a venue claim in the Events department.
     */
    public function isVenueClaimTicket(Ticket $ticket): bool
    {
        if (($ticket->ticket_type ?? null) !== 'venue_claim') {
            return false;
        }

        $department = $ticket->department;

        return $department && $department->name === 'Events';
    }

    /**
     * Build the structured metadata payload for the claim ticket.
     *
     * Follows the TicketPayloadRenderer schema convention. Privacy invariant:
     * only the venue NAME and CITY are stored — never the street address,
     * coordinates, postal code, or geohash.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function buildMetadata(User $claimant, Location $location, array $data): array
    {
        return [
            'schema' => 'venue_claim/v1',
            'actor' => ['type' => 'user', 'id' => $claimant->id, 'name' => $claimant->name],
            'action' => 'request',
            'entities' => [],
            'reason' => 'venue_claim',
            'details' => $data['claimant_notes'] ?? null,
            // Venue identity — name + city ONLY (no private address).
            'location_id' => (string) $location->id,
            'location_name' => trim((string) $location->name),
            'location_city' => $this->cityOnly($location),
            // Operator-submitted proof fields. contact_email is the claimant's
            // OWN provided contact — proof of affiliation, not venue address
            // data, so it does not touch the MEM717 address invariant.
            'claimant_notes' => $data['claimant_notes'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
        ];
    }

    /**
     * Build a human-readable description for the claim ticket (public-ish reply
     * surface). No private address.
     *
     * @param  array<string, mixed>  $data
     */
    protected function buildDescription(Location $location, array $data): string
    {
        $lines = [
            '**Venue:** '.trim((string) $location->name),
        ];

        $city = $this->cityOnly($location);
        if ($city !== '') {
            $lines[] = '**City:** '.$city;
        }

        if (! empty($data['website_url']) && is_string($data['website_url'])) {
            $lines[] = '**Website:** '.$data['website_url'];
        }

        if (! empty($data['contact_email']) && is_string($data['contact_email'])) {
            $lines[] = '**Contact email:** '.$data['contact_email'];
        }

        $lines[] = '';

        $notes = $data['claimant_notes'] ?? null;
        if (! empty($notes) && is_string($notes)) {
            $lines[] = '**Why I should manage this venue:**';
            $lines[] = $notes;
        } else {
            $lines[] = '**Notes:**';
            $lines[] = 'No additional notes provided.';
        }

        return implode("\n", $lines);
    }

    /**
     * Extract a city-only string for metadata/description. Returns '' when no
     * city is present, so nothing private is ever emitted.
     */
    protected function cityOnly(Location $location): string
    {
        $city = $location->city ?? null;

        return is_string($city) && $city !== '' ? trim($city) : '';
    }

    /**
     * Apply the venue-claim tag to the ticket (mirrors applyVenueProposalTag).
     */
    protected function applyVenueClaimTag(Ticket $ticket): void
    {
        $tag = Tag::firstOrCreate(
            ['name' => 'venue-claim'],
            ['color' => '#3B82F6']
        );

        $ticket->tags()->syncWithoutDetaching([$tag->id]);
    }

    /**
     * Resolve the currently authenticated user as the admin/causer, or null in
     * an unattended context.
     */
    protected function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Safely stringify a mixed metadata value (mirrors ViewTicket::asString).
     */
    protected static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
