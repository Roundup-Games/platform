<?php

use App\Enums\VenueType;
use App\Livewire\Venues\ProposeVenue;
use App\Models\Location;
use App\Models\User;
use App\Services\GeocodingService;
use App\Services\VenueProposalService;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();

    // Ensure Events department exists
    Department::firstOrCreate(
        ['name' => 'Events'],
        ['description' => 'Attendance disputes, event issues', 'is_active' => true]
    );

    $this->user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
});

// ── Authentication ───────────────────────────────────────

it('redirects unauthenticated user to login', function () {
    $response = $this->get(route('venues.propose'));

    $response->assertRedirect(route('login'));
});

it('renders form for authenticated user with complete profile', function () {
    $response = $this->actingAs($this->user)->get(route('venues.propose'));

    $response->assertStatus(200);
    $response->assertSeeLivewire(ProposeVenue::class);
});

// ── Validation ───────────────────────────────────────────

it('validates required fields on submit', function () {
    Livewire::actingAs($this->user)
        ->test(ProposeVenue::class)
        ->call('submit')
        ->assertHasErrors(['name', 'address', 'city', 'country', 'venue_type']);
});

it('validates country is max 3 characters', function () {
    Livewire::actingAs($this->user)
        ->test(ProposeVenue::class)
        ->set('country', 'TOOLONG')
        ->call('submit')
        ->assertHasErrors(['country']);
});

it('validates venue_type is a valid enum value', function () {
    Livewire::actingAs($this->user)
        ->test(ProposeVenue::class)
        ->set('venue_type', 'invalid_type')
        ->call('submit')
        ->assertHasErrors(['venue_type']);
});

it('validates website_url must be a valid URL', function () {
    Livewire::actingAs($this->user)
        ->test(ProposeVenue::class)
        ->set('website_url', 'not-a-url')
        ->call('submit')
        ->assertHasErrors(['website_url']);
});

// ── Successful Submission ────────────────────────────────

it('creates a ticket with correct ticket_type and metadata on successful submission', function () {
    $this->mock(GeocodingService::class, function ($mock) {
        $mock->shouldReceive('geocode')->andReturn([
            'lat' => '52.5200',
            'lng' => '13.4050',
            'display_name' => 'Berlin, Germany',
        ]);
    });

    Livewire::actingAs($this->user)
        ->test(ProposeVenue::class)
        ->set('name', 'Test Board Game Café')
        ->set('address', 'Musterstraße 42')
        ->set('city', 'Berlin')
        ->set('postal_code', '10115')
        ->set('country', 'DEU')
        ->set('venue_type', 'cafe')
        ->set('website_url', 'https://example.com')
        ->set('proposer_notes', 'Great venue for board gaming')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true)
        ->assertSet('ticketReference', fn ($value) => $value !== null);

    // Verify ticket was created
    $ticket = Ticket::where('ticket_type', 'venue_proposal')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->ticket_type)->toBe('venue_proposal');
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->metadata['venue_name'])->toBe('Test Board Game Café');
    expect($ticket->metadata['venue_address'])->toBe('Musterstraße 42');
    expect($ticket->metadata['venue_city'])->toBe('Berlin');
    expect($ticket->metadata['venue_country'])->toBe('DEU');
    expect($ticket->metadata['venue_type'])->toBe('cafe');
    expect($ticket->metadata['website_url'])->toBe('https://example.com');
    expect($ticket->metadata['notes'])->toBe('Great venue for board gaming');
    expect($ticket->metadata['latitude'])->toBe('52.5200');
    expect($ticket->metadata['longitude'])->toBe('13.4050');
    expect($ticket->metadata['geocoded_display_name'])->toBe('Berlin, Germany');
});

// ── Geocoding Graceful Failure ───────────────────────────

it('handles geocoding failure gracefully and still creates ticket', function () {
    $this->mock(GeocodingService::class, function ($mock) {
        $mock->shouldReceive('geocode')->andReturn(null);
    });

    Livewire::actingAs($this->user)
        ->test(ProposeVenue::class)
        ->set('name', 'Unknown Place')
        ->set('address', 'Somewhere')
        ->set('city', 'Nowhere')
        ->set('country', 'DEU')
        ->set('venue_type', 'flgs')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    $ticket = Ticket::where('ticket_type', 'venue_proposal')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->metadata['latitude'])->toBeNull();
    expect($ticket->metadata['longitude'])->toBeNull();
});

// ── Duplicate Detection (existing location) ──────────────

it('stores existing_location_id in metadata when matching location exists', function () {
    $existingLocation = Location::factory()->create([
        'name' => 'Existing Café',
        'city' => 'Berlin',
    ]);

    $this->mock(GeocodingService::class, function ($mock) {
        $mock->shouldReceive('geocode')->andReturn(null);
    });

    Livewire::actingAs($this->user)
        ->test(ProposeVenue::class)
        ->set('name', 'Existing Café')
        ->set('address', 'Different Address 5')
        ->set('city', 'Berlin')
        ->set('country', 'DEU')
        ->set('venue_type', 'cafe')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('existingLocation', true);

    $ticket = Ticket::where('ticket_type', 'venue_proposal')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->metadata['existing_location_id'])->toBe($existingLocation->id);
});

it('sets existingLocation to false when no matching location', function () {
    $this->mock(GeocodingService::class, function ($mock) {
        $mock->shouldReceive('geocode')->andReturn(null);
    });

    Livewire::actingAs($this->user)
        ->test(ProposeVenue::class)
        ->set('name', 'Brand New Venue')
        ->set('address', 'New Street 1')
        ->set('city', 'Munich')
        ->set('country', 'DEU')
        ->set('venue_type', 'library')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('existingLocation', false);

    $ticket = Ticket::where('ticket_type', 'venue_proposal')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->metadata['existing_location_id'])->toBeNull();
});

// ── Duplicate Proposal Prevention ────────────────────────

it('blocks submission when user already has pending proposal for same venue name', function () {
    $proposalService = app(VenueProposalService::class);

    // Create an existing pending proposal
    $proposalService->createProposal($this->user, [
        'name' => 'My Café',
        'address' => 'Street 1',
        'city' => 'Berlin',
        'country' => 'DEU',
        'venue_type' => 'cafe',
    ]);

    $this->mock(GeocodingService::class, function ($mock) {
        $mock->shouldReceive('geocode')->andReturn(null);
    });

    Livewire::actingAs($this->user)
        ->test(ProposeVenue::class)
        ->set('name', 'My Café')
        ->set('address', 'Street 1')
        ->set('city', 'Berlin')
        ->set('country', 'DEU')
        ->set('venue_type', 'cafe')
        ->call('submit')
        ->assertHasErrors(['name']);
});
