<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\VenueType;
use App\Livewire\Reviews\VenueReviews;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ═══════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════

/**
 * Build a verified commercial venue + a completed game at it + an approved
 * participant of that game. Returns the venue, the attendee, and the owner.
 *
 * Reuses the global createVerifiedVenue() helper from VenueDetailTest (forces
 * a commercial VenueType + slug so it passes the S02 isPublicVenuePage gate).
 * The game's date_time is in the past so canReviewVenue's "completed session"
 * arm (MEM735: Approved + past date_time) is satisfied.
 */
function createAttendedVenue(): array
{
    $venue = createVerifiedVenue();
    $system = GameSystem::factory()->create();
    $owner = User::factory()->create(['profile_complete' => true]);

    $game = Game::factory()->create([
        'location_id' => $venue->id,
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'date_time' => now()->subDay(),
    ]);

    $attendee = User::factory()->create(['profile_complete' => true]);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $attendee->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return compact('venue', 'attendee', 'owner', 'game');
}

// ═══════════════════════════════════════════════════════════
// DISPLAY
// ═══════════════════════════════════════════════════════════

describe('VenueReviews — display', function () {
    it('shows the empty state for a fresh venue with no reviews', function () {
        $venue = createVerifiedVenue();

        Livewire::test(VenueReviews::class, ['location' => $venue])
            ->assertOk()
            ->assertSee(__('venue.content_no_reviews'));
    })->group('smoke');

    it('renders the aggregate header and a published review in the list', function () {
        $venue = createVerifiedVenue();
        $reviewer = User::factory()->create(['name' => 'Agatha Reviewer']);

        Review::factory()->venue()->create([
            'reviewable_type' => Location::class,
            'reviewable_id' => $venue->id,
            'reviewer_id' => $reviewer->id,
            'rating' => 4,
            'body' => 'Lovely atmosphere and great tables.',
            'status' => 'published',
        ]);

        // The observer (T03) recomputes the aggregate columns on create.
        $venue->refresh();

        Livewire::test(VenueReviews::class, ['location' => $venue])
            ->assertOk()
            ->assertSee(number_format($venue->average_rating, 1))
            ->assertSee('Lovely atmosphere and great tables.')
            ->assertSee(trans_choice('venue.reviews_count', $venue->review_count));
    });

    it('excludes non-published reviews from the list', function () {
        $venue = createVerifiedVenue();
        $reviewer = User::factory()->create();

        Review::factory()->venue()->create([
            'reviewable_type' => Location::class,
            'reviewable_id' => $venue->id,
            'reviewer_id' => $reviewer->id,
            'body' => 'This reported review must be hidden.',
            'status' => 'reported',
        ]);

        Livewire::test(VenueReviews::class, ['location' => $venue])
            ->assertOk()
            ->assertDontSee('This reported review must be hidden.')
            ->assertSee(__('venue.content_no_reviews'));
    });

    it('lets a guest read the list but shows no write affordance', function () {
        $data = createAttendedVenue();
        // No actingAs — guest.

        Livewire::test(VenueReviews::class, ['location' => $data['venue']])
            ->assertOk()
            ->assertDontSee(__('venue.action_submit_venue_review'))
            ->assertDontSee(__('venue.content_not_eligible'));
    });
});

// ═══════════════════════════════════════════════════════════
// WRITE AFFORDANCE — ATTENDEE
// ═══════════════════════════════════════════════════════════

describe('VenueReviews — attended-only write', function () {
    it('shows the write form to an attendee of a completed session at the venue', function () {
        $data = createAttendedVenue();
        $this->actingAs($data['attendee']);

        Livewire::test(VenueReviews::class, ['location' => $data['venue']])
            ->assertOk()
            ->assertSee(__('venue.action_submit_venue_review'))
            ->assertSee(__('venue.label_your_rating'));
    });

    it('creates a venue review (polymorphic Location, null gm_profile_id) and updates the aggregate', function () {
        $data = createAttendedVenue();
        $venue = $data['venue'];
        $this->actingAs($data['attendee']);

        Livewire::test(VenueReviews::class, ['location' => $venue])
            ->set('rating', 5)
            ->set('body', 'Best venue in town, highly recommend.')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee(__('venue.flash_venue_review_submitted'));

        // Polymorphic venue review with no GM link.
        $this->assertDatabaseHas('reviews', [
            'reviewable_type' => Location::class,
            'reviewable_id' => $venue->id,
            'reviewer_id' => $data['attendee']->id,
            'gm_profile_id' => null,
            'rating' => 5,
            'status' => 'published',
        ]);

        $review = Review::first();
        expect($review->proficiency_tags)->toBeNull();

        // Observer (T03) recomputed the aggregate columns.
        $venue->refresh();
        expect($venue->review_count)->toBe(1)
            ->and((float) $venue->average_rating)->toBe(5.0);
    });

    it('blocks a second submission by the same user (unique-per-venue)', function () {
        $data = createAttendedVenue();
        $venue = $data['venue'];
        $this->actingAs($data['attendee']);

        // First review succeeds.
        Livewire::test(VenueReviews::class, ['location' => $venue])
            ->set('rating', 4)
            ->call('submit')
            ->assertHasNoErrors();

        // Second attempt: form is gone (canReviewVenue now false), and a
        // direct submit is rejected via the TOCTOU re-check.
        Livewire::test(VenueReviews::class, ['location' => $venue])
            ->assertDontSee(__('venue.action_submit_venue_review'))
            ->set('rating', 2)
            ->set('body', 'Trying again.')
            ->call('submit')
            ->assertSet('errorMessage', __('venue.content_not_eligible'));

        // Only one review persisted.
        expect(Review::where('reviewable_type', Location::class)->count())->toBe(1);
    });

    it('validates rating is required', function () {
        $data = createAttendedVenue();
        $this->actingAs($data['attendee']);

        Livewire::test(VenueReviews::class, ['location' => $data['venue']])
            ->set('body', 'No rating given.')
            ->call('submit')
            ->assertHasErrors(['rating']);
    });

    it('validates body max length', function () {
        $data = createAttendedVenue();
        $this->actingAs($data['attendee']);

        Livewire::test(VenueReviews::class, ['location' => $data['venue']])
            ->set('rating', 3)
            ->set('body', str_repeat('x', 2001))
            ->call('submit')
            ->assertHasErrors(['body']);
    });
});

// ═══════════════════════════════════════════════════════════
// WRITE AFFORDANCE — NON-ATTENDEE & NON-VENUE
// ═══════════════════════════════════════════════════════════

describe('VenueReviews — eligibility gate', function () {
    it('shows no form and rejects a direct submit from a non-attendee', function () {
        $data = createAttendedVenue();
        $stranger = User::factory()->create(['profile_complete' => true]);
        $this->actingAs($stranger);

        Livewire::test(VenueReviews::class, ['location' => $data['venue']])
            ->assertOk()
            ->assertDontSee(__('venue.action_submit_venue_review'))
            ->assertSee(__('venue.content_not_eligible'))
            // Direct submit attempt is rejected by the TOCTOU re-check.
            ->set('rating', 5)
            ->set('body', 'Sneaky non-attendee review.')
            ->call('submit')
            ->assertSet('errorMessage', __('venue.content_not_eligible'));

        $this->assertDatabaseMissing('reviews', [
            'reviewable_type' => Location::class,
            'reviewable_id' => $data['venue']->id,
            'reviewer_id' => $stranger->id,
        ]);
    });

    it('shows no form for an unverified/private location (canReviewVenue false)', function () {
        // A private location never reaches VenueDetail (404), but the component
        // is still defensive: canReviewVenue fails the isPublicVenuePage gate.
        $privateLocation = Location::factory()->create([
            'slug' => 'private-'.uniqid(),
            'is_verified' => false,
            'venue_type' => VenueType::Cafe,
        ]);
        $user = User::factory()->create(['profile_complete' => true]);
        $this->actingAs($user);

        Livewire::test(VenueReviews::class, ['location' => $privateLocation])
            ->assertOk()
            ->assertDontSee(__('venue.action_submit_venue_review'))
            ->assertSee(__('venue.content_not_eligible'));
    });

    it('shows no form for a verified-but-Other venue type', function () {
        $otherVenue = Location::factory()->verifiedVenue()->create([
            'slug' => 'other-'.uniqid(),
            'venue_type' => VenueType::Other,
        ]);
        $user = User::factory()->create(['profile_complete' => true]);
        $this->actingAs($user);

        Livewire::test(VenueReviews::class, ['location' => $otherVenue])
            ->assertOk()
            ->assertDontSee(__('venue.action_submit_venue_review'));
    });

    it('shows no form when the attendee has only an upcoming (not completed) session', function () {
        $venue = createVerifiedVenue();
        $system = GameSystem::factory()->create();
        $owner = User::factory()->create(['profile_complete' => true]);

        // Future game — the "completed session" arm is not satisfied.
        Game::factory()->create([
            'location_id' => $venue->id,
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'date_time' => now()->addDay(),
        ]);

        $user = User::factory()->create(['profile_complete' => true]);
        $game = Game::where('location_id', $venue->id)->first();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->actingAs($user);

        Livewire::test(VenueReviews::class, ['location' => $venue])
            ->assertOk()
            ->assertDontSee(__('venue.action_submit_venue_review'));
    });
});
