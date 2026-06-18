<?php

use App\Models\Location;
use App\Models\User;
use App\Services\VenueClaimService;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    seedRoles();

    // Ensure Events department exists
    $this->eventsDept = Department::firstOrCreate(
        ['name' => 'Events'],
        ['description' => 'Attendance disputes, event issues', 'is_active' => true]
    );

    $this->admin = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
    $this->admin->assignRole('Platform Admin');

    $this->user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
});

// ── Helper: create a venue claim ticket ──────────────

function createClaimTicket(Department $department, User $user, Location $location, array $metadataOverrides = []): Ticket
{
    return Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $user->id,
        'subject' => 'Venue Claim: '.($metadataOverrides['location_name'] ?? $location->name),
        'description' => 'A venue claim',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $department->id,
        'ticket_type' => 'venue_claim',
        'channel' => TicketChannel::Web->value,
        'metadata' => array_merge([
            'schema' => 'venue_claim/v1',
            'actor' => ['type' => 'user', 'id' => $user->id, 'name' => $user->name],
            'action' => 'request',
            'entities' => [],
            'reason' => 'venue_claim',
            'details' => 'I run regular events at this venue.',
            'location_id' => (string) $location->id,
            'location_name' => $location->name,
            'location_city' => $location->city ?? 'Berlin',
            'claimant_notes' => 'I run regular events at this venue.',
            'website_url' => 'https://example.com',
        ], $metadataOverrides),
    ]);
}

// ── Approve Action: Sets managed_by + Resolves ───────────

it('approve action sets managed_by on the existing location', function () {
    $location = Location::factory()->create([
        'name' => 'Claimed Hall',
        'managed_by' => null,
    ]);
    $ticket = createClaimTicket($this->eventsDept, $this->user, $location);

    $this->actingAs($this->admin);

    $claimService = app(VenueClaimService::class);
    $result = $claimService->approveClaim($ticket);

    expect($result->managed_by)->toBe($this->user->id);

    $location->refresh();
    expect($location->managed_by)->toBe($this->user->id);
});

it('approve action resolves the ticket', function () {
    $location = Location::factory()->create([
        'name' => 'Claimed Hall',
        'managed_by' => null,
    ]);
    $ticket = createClaimTicket($this->eventsDept, $this->user, $location);

    $this->actingAs($this->admin);

    $claimService = app(VenueClaimService::class);
    $claimService->approveClaim($ticket);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved);
});

it('approve action does not create a new location', function () {
    $location = Location::factory()->create([
        'name' => 'Claimed Hall',
        'managed_by' => null,
    ]);
    $ticket = createClaimTicket($this->eventsDept, $this->user, $location);

    $this->actingAs($this->admin);

    app(VenueClaimService::class)->approveClaim($ticket);

    // The existing location is reused; no new row with this name is created.
    expect(Location::where('name', 'Claimed Hall')->count())->toBe(1);
});

// ── Reject Action: Resolves Without Mutating Location ────

it('reject action leaves managed_by null and resolves the ticket', function () {
    $location = Location::factory()->create([
        'name' => 'Claimed Hall',
        'managed_by' => null,
    ]);
    $ticket = createClaimTicket($this->eventsDept, $this->user, $location);

    $this->actingAs($this->admin);

    app(VenueClaimService::class)->rejectClaim($ticket, 'Cannot verify association');

    $location->refresh();
    expect($location->managed_by)->toBeNull();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved);
});

// ── Ticket Type Filtering ────────────────────────────────

it('venue claim actions only appear on venue_claim tickets', function () {
    $location = Location::factory()->create(['name' => 'Claimed Hall']);
    $ticket = createClaimTicket($this->eventsDept, $this->user, $location);

    $claimService = app(VenueClaimService::class);
    expect($claimService->isVenueClaimTicket($ticket))->toBeTrue();
});

it('venue claim actions do not appear on regular tickets', function () {
    $regularTicket = Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $this->user->id,
        'subject' => 'General Inquiry',
        'description' => 'A regular support request',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $this->eventsDept->id,
        'ticket_type' => 'general_inquiry',
        'channel' => TicketChannel::Web->value,
        'metadata' => [],
    ]);

    $claimService = app(VenueClaimService::class);
    expect($claimService->isVenueClaimTicket($regularTicket))->toBeFalse();
});

it('does not treat tickets from other departments as venue claims', function () {
    $safetyDept = Department::firstOrCreate(
        ['name' => 'Safety'],
        ['description' => 'Safety department', 'is_active' => true]
    );

    $wrongDeptTicket = Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $this->user->id,
        'subject' => 'Venue Claim: Test',
        'description' => 'A venue claim in wrong department',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $safetyDept->id,
        'ticket_type' => 'venue_claim',
        'channel' => TicketChannel::Web->value,
        'metadata' => ['location_name' => 'Test'],
    ]);

    $claimService = app(VenueClaimService::class);
    expect($claimService->isVenueClaimTicket($wrongDeptTicket))->toBeFalse();
});

// ── Infolist Section Rendering (HTTP) ─────────────────────

it('renders the venue claim details section for venue_claim metadata', function () {
    $location = Location::factory()->create([
        'name' => 'Claimed Hall',
        'city' => 'Berlin',
        'managed_by' => null,
    ]);
    $ticket = createClaimTicket($this->eventsDept, $this->user, $location);

    actingAs($this->admin);
    Filament::setCurrentPanel('admin');

    $response = get("/admin/tickets/{$ticket->reference}");

    $response->assertSuccessful();
    $response->assertSee('Venue Claim Details');
    $response->assertSee('Claimed Hall');
    $response->assertSee('Claimed venue');
});

it('venue claim section surfaces the linked venue as managed once approved', function () {
    $location = Location::factory()->create([
        'name' => 'Claimed Hall',
        'city' => 'Berlin',
        'managed_by' => null,
    ]);
    $ticket = createClaimTicket($this->eventsDept, $this->user, $location);

    actingAs($this->admin);
    Filament::setCurrentPanel('admin');

    // Approve the claim → location becomes managed_by the claimant.
    app(VenueClaimService::class)->approveClaim($ticket);

    $response = get("/admin/tickets/{$ticket->reference}");

    $response->assertSuccessful();
    $response->assertSee('Claimed venue (now managed)');
});
