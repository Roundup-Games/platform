<?php

use App\Livewire\Reports\ReportContent;
use App\Livewire\Reviews\ReportReview;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;
use App\Services\VenueClaimService;
use App\Services\VenueProposalService;
use Escalated\Laravel\Contracts\TicketSubject;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Models\TicketSubjectLink;
use Livewire\Livewire;

/**
 * Verifies the escalated-laravel Ticket Subjects integration: host-app models
 * (Game, User, Campaign, Location, GameSystem, Review) attach as first-class
 * polymorphic subjects when their tickets are created, the relation is
 * queryable, and each model exposes a TicketSubject deep link.
 */
beforeEach(function () {
    seedRoles();

    Department::firstOrCreate(
        ['name' => 'Safety'],
        ['description' => 'content moderation', 'is_active' => true],
    );
    Department::firstOrCreate(
        ['name' => 'Events'],
        ['description' => 'venue claims/proposals', 'is_active' => true],
    );
});

it('attaches the reported entity as a subject on content reports', function () {
    $reporter = User::factory()->create();
    $reportedUser = User::factory()->create(['name' => 'Reported Person']);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'user', 'entityId' => $reportedUser->id])
        ->set('reason', 'harassment')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->firstOrFail();

    expect($ticket->subjects)->toHaveCount(1)
        ->and($ticket->subjects->first()->subject_type)->toBe(User::class)
        ->and($ticket->subjects->first()->subject_id)->toBe($reportedUser->id)
        ->and($ticket->subjects->first()->role)->toBe('reported')
        ->and($ticket->subjects->first()->subject)->toBeInstanceOf(User::class)
        ->and($ticket->subjects->first()->subject->is($reportedUser))->toBeTrue();
});

it('attaches both the review and its author as subjects on review reports', function () {
    $reporter = User::factory()->create();
    $gameOwner = User::factory()->create();
    $game = Game::factory()->for($gameOwner, 'owner')->create();
    $author = User::factory()->create();
    $review = Review::factory()
        ->forReviewable($game)
        ->create(['reviewer_id' => $author->id]);

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', 'spam')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'review_report')->firstOrFail();

    expect($ticket->subjects)->toHaveCount(2)
        ->and($ticket->subjects->pluck('subject_type')->unique()->values()->all())
        ->toMatchArray([Review::class, User::class])
        ->and($ticket->subjects->where('role', 'reported')->first()->subject_type)->toBe(Review::class)
        ->and($ticket->subjects->where('role', 'author')->first()->subject_type)->toBe(User::class);
});

it('attaches the venue as a subject on venue claims at creation time', function () {
    $claimant = User::factory()->create();
    $location = Location::factory()->create(['name' => 'The Dice Tower']);

    $ticket = app(VenueClaimService::class)
        ->createClaim($claimant, $location, ['claimant_notes' => 'I run this venue']);

    expect($ticket->subjects)->toHaveCount(1)
        ->and($ticket->subjects->first()->subject_type)->toBe(Location::class)
        ->and($ticket->subjects->first()->subject_id)->toBe($location->id)
        ->and($ticket->subjects->first()->role)->toBe('venue');
});

it('attaches the created Location as a subject when a venue proposal is approved', function () {
    $user = User::factory()->create();
    $proposalService = app(VenueProposalService::class);

    $ticket = $proposalService->createProposal($user, [
        'name' => 'Dragon\'s Lair',
        'address' => '123 Main St',
        'city' => 'Austin',
        'region' => 'TX',
        'postal_code' => '78701',
        'country' => 'US',
        'lat' => 30.27,
        'lng' => -97.74,
        'venue_type' => 'flgs',
    ]);

    // Before approval: no Location exists yet, so no subject.
    expect($ticket->subjects)->toBeEmpty();

    $proposalService->approveProposal($ticket);

    $ticket->refresh();
    expect($ticket->subjects)->toHaveCount(1)
        ->and($ticket->subjects->first()->subject_type)->toBe(Location::class)
        ->and($ticket->subjects->first()->role)->toBe('venue');
});

it('queries tickets by subject polymorphically (the hasPendingClaim use case)', function () {
    $claimant = User::factory()->create();
    $location = Location::factory()->create();

    app(VenueClaimService::class)
        ->createClaim($claimant, $location, ['claimant_notes' => 'I run this']);

    // The query subjects makes possible — replaces metadata JSON digging.
    $hasSubjectTicket = Ticket::whereHas('subjects', function ($q) use ($location) {
        $q->where('subject_type', Location::class)
            ->where('subject_id', $location->id);
    })->exists();

    $unrelatedLocation = Location::factory()->create();
    $noSubjectTicket = Ticket::whereHas('subjects', function ($q) use ($unrelatedLocation) {
        $q->where('subject_type', Location::class)
            ->where('subject_id', $unrelatedLocation->id);
    })->exists();

    expect($hasSubjectTicket)->toBeTrue()
        ->and($noSubjectTicket)->toBeFalse();
});

it('exposes model-owned deep links via the TicketSubject contract', function () {
    $game = Game::factory()->create(['name' => 'Catan Night']);
    $campaign = Campaign::factory()->create(['name' => 'Weekly D&D']);
    $user = User::factory()->create(['name' => 'Alice', 'slug' => 'alice']);
    $userWithoutSlug = User::factory()->make(['slug' => null]);
    $userWithoutSlug->slug = null; // bypass the creating observer's backfill for this defensive assertion
    $location = Location::factory()->create(['name' => 'The Shop']);

    expect($game)->toBeInstanceOf(TicketSubject::class)
        ->and($game->ticketSubjectTitle())->toBe('Catan Night')
        ->and($game->ticketSubjectUrl())->toContain('/games/')
        ->and($campaign->ticketSubjectUrl())->toContain('/campaigns/')
        ->and($user->ticketSubjectUrl())->toContain('/u/')
        ->and($userWithoutSlug->ticketSubjectUrl())->toBeNull()  // defensive: no route without slug
        ->and($location->ticketSubjectUrl())->toBeNull();        // Location has no public route
});

it('enforces the unique ticket+type+id key (attachSubject is idempotent)', function () {
    $claimant = User::factory()->create();
    $location = Location::factory()->create();

    $ticket = app(VenueClaimService::class)
        ->createClaim($claimant, $location, ['claimant_notes' => 'one']);

    // Re-attaching the same entity must not duplicate.
    $ticket->attachSubject($location, 'venue');
    $ticket->attachSubject($location, 'venue');

    expect($ticket->subjects()->count())->toBe(1)
        ->and(TicketSubjectLink::where('ticket_id', $ticket->id)->count())->toBe(1);
});

it('cascade-deletes subjects when a ticket is force-deleted', function () {
    // Ticket uses SoftDeletes, so a normal delete() is a soft-delete that
    // preserves the subjects row (the relation stays restorable). Only a
    // forceDelete() triggers the DB-level cascadeOnDelete on the FK.
    $claimant = User::factory()->create();
    $location = Location::factory()->create();

    $ticket = app(VenueClaimService::class)
        ->createClaim($claimant, $location, ['claimant_notes' => 'temp']);
    $ticketId = $ticket->id;

    $ticket->forceDelete();

    expect(TicketSubjectLink::where('ticket_id', $ticketId)->exists())->toBeFalse();
});
