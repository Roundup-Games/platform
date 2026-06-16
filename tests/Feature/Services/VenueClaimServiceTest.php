<?php

use App\Enums\VenueType;
use App\Models\Location;
use App\Models\User;
use App\Services\VenueClaimService;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    seedRoles();

    $this->eventsDept = Department::firstOrCreate(
        ['name' => 'Events'],
        ['description' => 'Attendance disputes, event issues', 'is_active' => true]
    );

    $this->claimant = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $this->admin = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
    $this->admin->assignRole('Platform Admin');

    // A public, verified commercial venue that is claimable (managed_by unset).
    $this->venue = Location::factory()->verifiedVenue()->create([
        'name' => 'Dragon’s Lair Café',
        'city' => 'Berlin',
        'address' => 'Secret Street 12',
        'postal_code' => '10115',
        'country' => 'DEU',
        'latitude' => '52.5200',
        'longitude' => '13.4050',
        'managed_by' => null,
        'slug' => 'dragons-lair-cafe',
        'venue_type' => VenueType::Cafe,
    ]);
});

// ── Helper: build a venue claim ticket directly (bypassing the service) ──

function createVenueClaimTicket(Department $department, User $claimant, Location $location, array $metadataOverrides = []): Ticket
{
    return Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $claimant->id,
        'subject' => 'Venue Claim: '.$location->name,
        'description' => 'A venue claim',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $department->id,
        'ticket_type' => 'venue_claim',
        'channel' => TicketChannel::Web->value,
        'metadata' => array_merge([
            'schema' => 'venue_claim/v1',
            'actor' => ['type' => 'user', 'id' => $claimant->id, 'name' => $claimant->name],
            'reason' => 'venue_claim',
            'location_id' => (string) $location->id,
            'location_name' => $location->name,
            'location_city' => 'Berlin',
            'claimant_notes' => 'I run this venue.',
            'website_url' => 'https://example.com',
        ], $metadataOverrides),
    ]);
}

// ───────────────────────────────────────────────────────
// createClaim: ticket shape
// ───────────────────────────────────────────────────────

it('createClaim creates a venue_claim ticket with the correct shape', function () {
    $service = app(VenueClaimService::class);

    $ticket = $service->createClaim($this->claimant, $this->venue, [
        'claimant_notes' => 'I am the operator.',
        'website_url' => 'https://dragonslair.example',
    ]);

    expect($ticket->ticket_type)->toBe('venue_claim');
    expect($ticket->department_id)->toBe($this->eventsDept->id);
    expect($ticket->department->name)->toBe('Events');
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->priority)->toBe(TicketPriority::Medium);
    expect($ticket->channel)->toBe(TicketChannel::Web);
    expect($ticket->requester_id)->toBe($this->claimant->id);
    expect($ticket->subject)->toBe('Venue Claim: Dragon’s Lair Café');

    $metadata = $ticket->metadata;
    expect($metadata['schema'])->toBe('venue_claim/v1');
    expect($metadata['reason'])->toBe('venue_claim');
    expect($metadata['actor'])->toBe(['type' => 'user', 'id' => $this->claimant->id, 'name' => $this->claimant->name]);
    expect($metadata['location_id'])->toBe((string) $this->venue->id);
    expect($metadata['location_name'])->toBe('Dragon’s Lair Café');
    expect($metadata['claimant_notes'])->toBe('I am the operator.');
    expect($metadata['website_url'])->toBe('https://dragonslair.example');

    // venue-claim tag applied
    $tag = Tag::where('name', 'venue-claim')->first();
    expect($tag)->not->toBeNull();
    expect($ticket->tags->pluck('name'))->toContain('venue-claim');
});

// ───────────────────────────────────────────────────────
// createClaim: privacy invariant (MEM717) — no private address
// ───────────────────────────────────────────────────────

it('createClaim never embeds a private address or coordinates in metadata', function () {
    $service = app(VenueClaimService::class);

    $ticket = $service->createClaim($this->claimant, $this->venue, [
        'claimant_notes' => 'Operator here.',
    ]);

    $metadata = $ticket->metadata;

    // City + name ARE allowed (page identity).
    expect($metadata['location_city'])->toBe('Berlin');
    expect($metadata)->toHaveKey('location_name');

    // Private address material must never leak into claim metadata.
    foreach (['location_address', 'address', 'latitude', 'longitude', 'postal_code', 'geohash', 'geohash_4', 'place_id'] as $forbidden) {
        expect($metadata)->not->toHaveKey($forbidden);
    }
});

it('createClaim description never contains the street address or postal code', function () {
    $service = app(VenueClaimService::class);

    $ticket = $service->createClaim($this->claimant, $this->venue, [
        'claimant_notes' => 'Operator here.',
    ]);

    expect($ticket->description)->toContain('Dragon’s Lair Café')
        ->and($ticket->description)->toContain('Berlin')
        ->and($ticket->description)->not->toContain('Secret Street 12')
        ->and($ticket->description)->not->toContain('10115');
});

// ───────────────────────────────────────────────────────
// createClaim: structured logging + department-missing failure
// ───────────────────────────────────────────────────────

it('createClaim logs venue_claim.submitted with ticket + location context', function () {
    Log::spy();

    $service = app(VenueClaimService::class);
    $service->createClaim($this->claimant, $this->venue, ['claimant_notes' => 'mine']);

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message) => $message === 'venue_claim.submitted')
        ->once();
});

it('createClaim throws and logs when the Events department is missing', function () {
    Department::where('name', 'Events')->delete();

    Log::spy();
    $service = app(VenueClaimService::class);

    expect(fn () => $service->createClaim($this->claimant, $this->venue, ['claimant_notes' => 'mine']))
        ->toThrow(RuntimeException::class, 'Events department');

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => $message === 'venue_claim.events_department_missing')
        ->once();
});

// ───────────────────────────────────────────────────────
// isVenueClaimTicket classification
// ───────────────────────────────────────────────────────

it('isVenueClaimTicket identifies a real claim and rejects others', function () {
    $service = app(VenueClaimService::class);

    $claim = createVenueClaimTicket($this->eventsDept, $this->claimant, $this->venue);
    expect($service->isVenueClaimTicket($claim))->toBeTrue();

    // Wrong ticket_type (venue_proposal) → false
    $proposal = Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $this->claimant->id,
        'subject' => 'Venue Proposal: X',
        'description' => '-',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $this->eventsDept->id,
        'ticket_type' => 'venue_proposal',
        'channel' => TicketChannel::Web->value,
        'metadata' => [],
    ]);
    expect($service->isVenueClaimTicket($proposal))->toBeFalse();

    // Right type, wrong department → false
    $safety = Department::firstOrCreate(['name' => 'Safety'], ['description' => 'safety', 'is_active' => true]);
    $wrongDept = createVenueClaimTicket($safety, $this->claimant, $this->venue);
    expect($service->isVenueClaimTicket($wrongDept))->toBeFalse();
});

// ───────────────────────────────────────────────────────
// hasPendingClaim duplicate guard
// ───────────────────────────────────────────────────────

it('hasPendingClaim blocks a duplicate pending claim for the same user + location', function () {
    $service = app(VenueClaimService::class);

    expect($service->hasPendingClaim($this->claimant, $this->venue))->toBeFalse();

    $service->createClaim($this->claimant, $this->venue, ['claimant_notes' => 'mine']);

    expect($service->hasPendingClaim($this->claimant, $this->venue))->toBeTrue();
});

it('hasPendingClaim is scoped to the specific location and claimant', function () {
    $service = app(VenueClaimService::class);
    $service->createClaim($this->claimant, $this->venue, ['claimant_notes' => 'mine']);

    // Different user claiming the same venue → not blocked (each user can file their own).
    $other = User::factory()->create();
    expect($service->hasPendingClaim($other, $this->venue))->toBeFalse();

    // Same user, different venue → not blocked.
    $otherVenue = Location::factory()->verifiedVenue()->create(['managed_by' => null]);
    expect($service->hasPendingClaim($this->claimant, $otherVenue))->toBeFalse();
});

it('hasPendingClaim ignores resolved claims', function () {
    $ticket = createVenueClaimTicket($this->eventsDept, $this->claimant, $this->venue);
    $ticket->update(['status' => TicketStatus::Resolved->value]);

    $service = app(VenueClaimService::class);
    expect($service->hasPendingClaim($this->claimant, $this->venue))->toBeFalse();
});

// ───────────────────────────────────────────────────────
// approveClaim: happy path
// ───────────────────────────────────────────────────────

it('approveClaim sets managed_by to the claimant and resolves the ticket', function () {
    $this->actingAs($this->admin);
    $service = app(VenueClaimService::class);

    $ticket = $service->createClaim($this->claimant, $this->venue, ['claimant_notes' => 'mine']);
    expect($this->venue->fresh()->managed_by)->toBeNull();

    $location = $service->approveClaim($ticket);

    expect($location->managed_by)->toBe($this->claimant->id);
    expect($this->venue->fresh()->managed_by)->toBe($this->claimant->id);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved);
});

it('approveClaim logs venue_claim.approved', function () {
    $this->actingAs($this->admin);
    Log::spy();

    $service = app(VenueClaimService::class);
    $ticket = $service->createClaim($this->claimant, $this->venue, ['claimant_notes' => 'mine']);
    $service->approveClaim($ticket);

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message) => $message === 'venue_claim.approved')
        ->once();
});

it('approveClaim defensively backfills a slug when the venue has none', function () {
    $this->actingAs($this->admin);
    $slugless = Location::factory()->verifiedVenue()->create([
        'name' => 'Slugless Boardgame Hall',
        'managed_by' => null,
        'slug' => null,
    ]);

    $service = app(VenueClaimService::class);
    $ticket = $service->createClaim($this->claimant, $slugless, ['claimant_notes' => 'mine']);
    $service->approveClaim($ticket);

    $slugless->refresh();
    expect($slugless->managed_by)->toBe($this->claimant->id);
    expect($slugless->slug)->not->toBeNull();
    expect($slugless->slug)->not->toBe('');
});

// ───────────────────────────────────────────────────────
// approveClaim: guards / negative paths
// ───────────────────────────────────────────────────────

it('approveClaim rejects a ticket that is already managed by another user', function () {
    $this->actingAs($this->admin);
    $otherManager = User::factory()->create();
    $this->venue->update(['managed_by' => $otherManager->id]);

    $service = app(VenueClaimService::class);
    $ticket = $service->createClaim($this->claimant, $this->venue, ['claimant_notes' => 'mine']);

    expect(fn () => $service->approveClaim($ticket))
        ->toThrow(RuntimeException::class, 'already managed by another user');

    // Location untouched, ticket still open.
    expect($this->venue->fresh()->managed_by)->toBe($otherManager->id);
    expect($ticket->fresh()->status)->toBe(TicketStatus::Open);
});

it('approveClaim is idempotent when the venue is already managed by the same claimant', function () {
    $this->actingAs($this->admin);
    $this->venue->update(['managed_by' => $this->claimant->id]);

    $service = app(VenueClaimService::class);
    $ticket = $service->createClaim($this->claimant, $this->venue, ['claimant_notes' => 'mine']);

    // Should NOT throw; managed_by stays the claimant; ticket resolves.
    $location = $service->approveClaim($ticket);
    expect($location->managed_by)->toBe($this->claimant->id);
    expect($ticket->fresh()->status)->toBe(TicketStatus::Resolved);
});

it('approveClaim refuses a ticket that is no longer open', function () {
    $this->actingAs($this->admin);
    $service = app(VenueClaimService::class);
    $ticket = $service->createClaim($this->claimant, $this->venue, ['claimant_notes' => 'mine']);
    $ticket->update(['status' => TicketStatus::Resolved->value]);

    expect(fn () => $service->approveClaim($ticket))
        ->toThrow(RuntimeException::class, 'no longer open');

    expect($this->venue->fresh()->managed_by)->toBeNull();
});

it('approveClaim refuses a non-venue-claim ticket', function () {
    $this->actingAs($this->admin);
    $proposal = Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $this->claimant->id,
        'subject' => 'Venue Proposal: X',
        'description' => '-',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $this->eventsDept->id,
        'ticket_type' => 'venue_proposal',
        'channel' => TicketChannel::Web->value,
        'metadata' => [],
    ]);

    $service = app(VenueClaimService::class);
    expect(fn () => $service->approveClaim($proposal))
        ->toThrow(InvalidArgumentException::class, 'not a venue claim');
});

it('approveClaim throws when the metadata has no claimant actor id', function () {
    $this->actingAs($this->admin);
    $ticket = createVenueClaimTicket($this->eventsDept, $this->claimant, $this->venue, [
        'actor' => ['type' => 'user', 'id' => null, 'name' => 'Nobody'],
    ]);

    $service = app(VenueClaimService::class);
    expect(fn () => $service->approveClaim($ticket))
        ->toThrow(RuntimeException::class, 'missing a claimant');

    expect($this->venue->fresh()->managed_by)->toBeNull();
});

it('approveClaim throws when the target location no longer exists', function () {
    $this->actingAs($this->admin);
    $service = app(VenueClaimService::class);

    // Create a ticket whose location_id points at a location we then delete.
    $ticket = createVenueClaimTicket($this->eventsDept, $this->claimant, $this->venue, []);
    $ticket->updateQuietly([
        'metadata' => array_merge($ticket->metadata, ['location_id' => (string) Str::orderedUuid()]),
    ]);

    expect(fn () => $service->approveClaim($ticket))
        ->toThrow(RuntimeException::class, 'no longer exists');
});

// ───────────────────────────────────────────────────────
// rejectClaim: resolves without mutating Location
// ───────────────────────────────────────────────────────

it('rejectClaim resolves the ticket and leaves managed_by null', function () {
    $this->actingAs($this->admin);
    $service = app(VenueClaimService::class);

    $ticket = $service->createClaim($this->claimant, $this->venue, ['claimant_notes' => 'mine']);
    expect($this->venue->fresh()->managed_by)->toBeNull();

    $service->rejectClaim($ticket, 'No proof of affiliation.');

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved);
    expect($this->venue->fresh()->managed_by)->toBeNull();
});

it('rejectClaim logs venue_claim.rejected', function () {
    $this->actingAs($this->admin);
    Log::spy();

    $service = app(VenueClaimService::class);
    $ticket = $service->createClaim($this->claimant, $this->venue, ['claimant_notes' => 'mine']);
    $service->rejectClaim($ticket, 'Duplicate.');

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message) => $message === 'venue_claim.rejected')
        ->once();
});
