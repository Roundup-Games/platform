<?php

use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\Location;
use App\Models\User;
use App\Services\VenueProposalService;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\TicketService;

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

// ── Helper: create a venue proposal ticket ──────────────

function createVenueProposalTicket(Department $department, User $user, array $metadataOverrides = []): Ticket
{
    return Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $user->id,
        'subject' => 'Venue Proposal: ' . ($metadataOverrides['venue_name'] ?? 'Test Venue'),
        'description' => 'A venue proposal',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $department->id,
        'ticket_type' => 'venue_proposal',
        'channel' => TicketChannel::Web->value,
        'metadata' => array_merge([
            'schema' => 'venue_proposal/v1',
            'venue_name' => 'Test Venue',
            'venue_address' => 'Test Street 1',
            'venue_city' => 'Berlin',
            'venue_postal_code' => '10115',
            'venue_country' => 'DEU',
            'venue_type' => 'cafe',
            'website_url' => 'https://example.com',
            'proposer_notes' => 'A great place',
            'latitude' => '52.5200',
            'longitude' => '13.4050',
            'geocoded_display_name' => 'Berlin, Germany',
            'existing_location_id' => null,
            'location_id' => null,
        ], $metadataOverrides),
    ]);
}

// ── Approve Action: Create New Location ──────────────────

it('approve action creates a new verified location from ticket metadata', function () {
    $ticket = createVenueProposalTicket($this->eventsDept, $this->user);

    $proposalService = app(VenueProposalService::class);
    $location = $proposalService->approveProposal($ticket);

    expect($location)->not->toBeNull();
    expect($location->name)->toBe('Test Venue');
    expect($location->address)->toBe('Test Street 1');
    expect($location->is_verified)->toBeTrue();
    expect($location->venue_type->value)->toBe('cafe');
    expect($location->website_url)->toBe('https://example.com');
    expect($location->source)->toBe('venue_proposal');
});

it('approve action updates existing location when existing_location_id is in metadata', function () {
    $existingLocation = Location::factory()->create([
        'name' => 'Existing Café',
        'address' => 'Old Address 1',
        'is_verified' => false,
    ]);

    $ticket = createVenueProposalTicket($this->eventsDept, $this->user, [
        'venue_name' => 'Existing Café',
        'venue_address' => 'Old Address 1',
        'existing_location_id' => $existingLocation->id,
    ]);

    $proposalService = app(VenueProposalService::class);
    $location = $proposalService->approveProposal($ticket);

    // Should update the existing location, not create a new one
    expect($location->id)->toBe($existingLocation->id);
    expect($location->is_verified)->toBeTrue();
    expect($location->venue_type->value)->toBe('cafe');
    expect($location->fresh()->venue_metadata['approved_from_ticket'])->toBe($ticket->reference);
});

// ── Approve Action: Resolves Ticket ──────────────────────

it('approve action resolves the ticket', function () {
    $ticket = createVenueProposalTicket($this->eventsDept, $this->user);

    $this->actingAs($this->admin);

    $proposalService = app(VenueProposalService::class);
    $ticketService = app(TicketService::class);

    $location = $proposalService->approveProposal($ticket);
    $ticketService->resolve($ticket, $this->admin);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved);
});

// ── Reject Action: Resolves Without Creating Location ────

it('reject action resolves ticket without creating location', function () {
    $ticket = createVenueProposalTicket($this->eventsDept, $this->user);

    $this->actingAs($this->admin);

    $ticketService = app(TicketService::class);
    $ticketService->reply($ticket, $this->admin, 'Venue proposal rejected: Does not meet guidelines.');
    $ticketService->addNote($ticket, $this->admin, 'Venue proposal rejected. Reason: Does not meet guidelines.');
    $ticketService->resolve($ticket, $this->admin);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved);

    // No location should have been created
    $locationCount = Location::where('name', 'Test Venue')->count();
    expect($locationCount)->toBe(0);
});

// ── Ticket Type Filtering ────────────────────────────────

it('venue proposal actions only appear on venue_proposal tickets', function () {
    $venueTicket = createVenueProposalTicket($this->eventsDept, $this->user);

    $proposalService = app(VenueProposalService::class);
    expect($proposalService->isVenueProposalTicket($venueTicket))->toBeTrue();
});

it('venue proposal actions do not appear on regular tickets', function () {
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

    $proposalService = app(VenueProposalService::class);
    expect($proposalService->isVenueProposalTicket($regularTicket))->toBeFalse();
});

it('does not treat tickets from other departments as venue proposals', function () {
    $safetyDept = Department::firstOrCreate(
        ['name' => 'Safety'],
        ['description' => 'Safety department', 'is_active' => true]
    );

    $wrongDeptTicket = Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $this->user->id,
        'subject' => 'Venue Proposal: Test',
        'description' => 'A venue proposal in wrong department',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $safetyDept->id,
        'ticket_type' => 'venue_proposal',
        'channel' => TicketChannel::Web->value,
        'metadata' => ['venue_name' => 'Test'],
    ]);

    $proposalService = app(VenueProposalService::class);
    expect($proposalService->isVenueProposalTicket($wrongDeptTicket))->toBeFalse();
});

// ── Approve Action: Metadata Updated ─────────────────────

it('approve action records location_id in ticket metadata', function () {
    $ticket = createVenueProposalTicket($this->eventsDept, $this->user);

    $proposalService = app(VenueProposalService::class);
    $location = $proposalService->approveProposal($ticket);

    $ticket->refresh();
    expect($ticket->metadata['location_id'])->toBe($location->id);
});
