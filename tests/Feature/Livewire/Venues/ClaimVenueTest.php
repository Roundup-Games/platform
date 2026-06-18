<?php

use App\Enums\VenueType;
use App\Livewire\Venues\ClaimVenue;
use App\Models\Location;
use App\Models\User;
use App\Services\VenueClaimService;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Livewire\Livewire;

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

/**
 * Create a verified *commercial* venue with an explicit slug + address.
 *
 * Mirrors VenueDetailTest::createVerifiedVenue(): verifiedVenue() picks a
 * random VenueType (which can include Other), so the type is forced to a
 * commercial type. The slug is explicit because Location has no
 * auto-slug-on-save hook.
 */
function createClaimableVenue(array $overrides = []): Location
{
    return Location::factory()->verifiedVenue()->create(array_merge([
        'venue_type' => VenueType::Cafe,
        'slug' => 'claimable-venue-'.uniqid(),
        'name' => 'Claimable Venue '.uniqid(),
        'address' => '123 Test Street',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'country' => 'DEU',
        'managed_by' => null,
    ], $overrides));
}

beforeEach(function () {
    seedRoles();

    // VenueClaimService requires the Events department to exist.
    Department::firstOrCreate(
        ['name' => 'Events'],
        ['description' => 'Attendance disputes, event issues', 'is_active' => true]
    );

    $this->user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);
});

// ═══════════════════════════════════════════════════════════
// AUTHENTICATION / ROUTE GATING
// ═══════════════════════════════════════════════════════════

describe('ClaimVenue route gating', function () {
    it('redirects unauthenticated users to login', function () {
        $venue = createClaimableVenue();

        $response = $this->get(route('venues.claim', ['slug' => $venue->slug]));

        $response->assertRedirect(route('login'));
    });

    it('redirects authenticated users with an incomplete profile', function () {
        $incomplete = User::factory()->create(['profile_complete' => false]);
        $venue = createClaimableVenue();

        $response = $this->actingAs($incomplete)->get(route('venues.claim', ['slug' => $venue->slug]));

        // profile.complete middleware bounces incomplete profiles away from the form.
        $response->assertRedirect();
        $response->assertSessionMissing('_old_input');
    });

    it('renders the form for an authenticated user with a complete profile', function () {
        $venue = createClaimableVenue(['name' => 'The Dice Hall']);

        $response = $this->actingAs($this->user)->get(route('venues.claim', ['slug' => $venue->slug]));

        $response->assertStatus(200);
        $response->assertSeeLivewire(ClaimVenue::class);
        $response->assertSee('The Dice Hall');
    });

    it('404s for a non-public location (mount re-runs the isPublicVenuePage gate)', function () {
        $private = Location::factory()->create([
            'slug' => 'private-'.uniqid(),
            'is_verified' => false,
        ]);

        $this->actingAs($this->user)
            ->get(route('venues.claim', ['slug' => $private->slug]))
            ->assertNotFound();
    });
});

// ═══════════════════════════════════════════════════════════
// VALIDATION
// ═══════════════════════════════════════════════════════════

describe('ClaimVenue validation', function () {
    it('requires a justification', function () {
        $venue = createClaimableVenue();

        Livewire::actingAs($this->user)
            ->test(ClaimVenue::class, ['slug' => $venue->slug])
            ->call('submit')
            ->assertHasErrors(['justification']);
    });

    it('validates website_url is a valid URL and contact_email is a valid email', function () {
        $venue = createClaimableVenue();

        Livewire::actingAs($this->user)
            ->test(ClaimVenue::class, ['slug' => $venue->slug])
            ->set('justification', 'I run this place.')
            ->set('website_url', 'not-a-url')
            ->set('contact_email', 'not-an-email')
            ->call('submit')
            ->assertHasErrors(['website_url', 'contact_email']);
    });
});

// ═══════════════════════════════════════════════════════════
// SUCCESSFUL SUBMISSION
// ═══════════════════════════════════════════════════════════

describe('ClaimVenue submission', function () {
    it('creates a venue_claim ticket with correct metadata and flips to the success state', function () {
        $venue = createClaimableVenue(['name' => 'Dragon’s Den']);

        Livewire::actingAs($this->user)
            ->test(ClaimVenue::class, ['slug' => $venue->slug])
            ->set('justification', 'I am the operator.')
            ->set('website_url', 'https://dragonsden.example')
            ->set('contact_email', 'owner@dragonsden.example')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true)
            ->assertSet('ticketReference', fn ($value) => $value !== null);

        $ticket = Ticket::where('ticket_type', 'venue_claim')->first();
        expect($ticket)->not->toBeNull();
        expect($ticket->status)->toBe(TicketStatus::Open);
        expect($ticket->requester_id)->toBe($this->user->id);

        $metadata = $ticket->metadata;
        expect($metadata['schema'])->toBe('venue_claim/v1');
        expect($metadata['reason'])->toBe('venue_claim');
        expect($metadata['location_id'])->toBe((string) $venue->id);
        expect($metadata['location_name'])->toBe('Dragon’s Den');
        // City-only venue identity (MEM717): city is stored, never the address.
        expect($metadata['location_city'])->toBe('Berlin');
        expect($metadata['claimant_notes'])->toBe('I am the operator.');
        expect($metadata['website_url'])->toBe('https://dragonsden.example');
        expect($metadata['contact_email'])->toBe('owner@dragonsden.example');
    });

    it('succeeds with only the required justification (optional proof fields empty)', function () {
        $venue = createClaimableVenue();

        Livewire::actingAs($this->user)
            ->test(ClaimVenue::class, ['slug' => $venue->slug])
            ->set('justification', 'I run this venue.')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $ticket = Ticket::where('ticket_type', 'venue_claim')->first();
        expect($ticket)->not->toBeNull();
        expect($ticket->metadata['claimant_notes'])->toBe('I run this venue.');
        expect($ticket->metadata['website_url'])->toBeNull();
        expect($ticket->metadata['contact_email'])->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// DUPLICATE / RATE-LIMIT GUARDS
// ═══════════════════════════════════════════════════════════

describe('ClaimVenue guards', function () {
    it('blocks a second claim while one is already pending for the same venue', function () {
        $venue = createClaimableVenue();
        $service = app(VenueClaimService::class);

        // Seed an open claim directly through the service.
        $service->createClaim($this->user, $venue, [
            'claimant_notes' => 'First claim.',
        ]);

        Livewire::actingAs($this->user)
            ->test(ClaimVenue::class, ['slug' => $venue->slug])
            ->set('justification', 'Second claim attempt.')
            ->call('submit')
            ->assertHasErrors(['justification']);

        // No second ticket should have been created.
        expect(Ticket::where('ticket_type', 'venue_claim')->count())->toBe(1);
    });

    it('rate-limits after 3 claims in a day (per user, across venues)', function () {
        $venues = [
            createClaimableVenue(),
            createClaimableVenue(),
            createClaimableVenue(),
            createClaimableVenue(),
        ];

        // Three successful claims on three distinct venues consume the budget.
        for ($i = 0; $i < 3; $i++) {
            Livewire::actingAs($this->user)
                ->test(ClaimVenue::class, ['slug' => $venues[$i]->slug])
                ->set('justification', 'Claim number '.($i + 1))
                ->call('submit')
                ->assertHasNoErrors()
                ->assertSet('submitted', true);
        }

        // The 4th distinct-venue claim is rate-limited (no pending claim for
        // this venue, so the duplicate guard does not fire first).
        Livewire::actingAs($this->user)
            ->test(ClaimVenue::class, ['slug' => $venues[3]->slug])
            ->set('justification', 'Claim number 4')
            ->call('submit')
            ->assertHasErrors(['justification']);

        expect(Ticket::where('ticket_type', 'venue_claim')->count())->toBe(3);
    });
});

// ═══════════════════════════════════════════════════════════
// VENUE-PAGE ENTRY POINT (venue-detail.blade.php)
// ═══════════════════════════════════════════════════════════

describe('venue page claim entry point', function () {
    it('shows the claim-this-venue link for an authenticated visitor when the venue is unmanaged', function () {
        $venue = createClaimableVenue();

        $response = $this->actingAs($this->user)->get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $response->assertSee(__('venue.action_claim_venue'));
        $response->assertSee(route('venues.claim', ['locale' => 'en', 'slug' => $venue->slug]));
    });

    it('hides the claim-this-venue link once the venue has a manager', function () {
        $manager = User::factory()->create(['name' => 'Manager Bob']);
        $venue = createClaimableVenue(['managed_by' => $manager->id]);

        $response = $this->actingAs($this->user)->get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        // Managed venue shows the manager, never the claim affordance.
        $response->assertSee('Manager Bob');
        $response->assertDontSee(__('venue.action_claim_venue'));
        $response->assertDontSee(route('venues.claim', ['locale' => 'en', 'slug' => $venue->slug]));
    });

    it('hides the claim-this-venue link for guests (auth-gated affordance)', function () {
        $venue = createClaimableVenue();

        $response = $this->get(route('venues.detail', ['slug' => $venue->slug]));
        $response->assertOk();

        $response->assertDontSee(__('venue.action_claim_venue'));
    });
});
