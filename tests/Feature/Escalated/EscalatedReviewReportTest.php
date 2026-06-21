<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\VenueType;
use App\Livewire\Reviews\ReportReview;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\Location;
use App\Models\Review;
use App\Models\User;
use App\Notifications\ReviewReported;
use App\Services\ReviewAggregateService;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\EscalationRule;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\EscalationService;
use Escalated\Laravel\Services\TicketService;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

// Escalated review-report pipeline: game reviews (reviewable_type=Game) and
// venue reviews (reviewable_type=Location) both flow through the SAME generic
// ReportReview Livewire component → Safety-department Escalated ticket → admin
// moderation. The only divergence is (a) how the reviewable + its review are
// built (per-type factories below) and (b) which aggregate the ReviewObserver
// recomputes (GMProfile for game reviews, Location for venue reviews). Every
// moderation scenario (dismiss/remove/escalate/notify/auto-escalate) is
// assertion-identical across the two types, so they share helpers.
//
// Regression guard: venue reports must keep using the UNCHANGED generic
// ReportReview → Escalated pipeline with no venue-specific code in
// ReportReview/TicketPayloadRenderer/ViewTicket (MEM527/D083). The venue
// aggregate recalculation runs through the ReviewObserver's Location branch
// (T03); the venue review itself is written exactly like VenueReviews (T04)
// writes it. If any venue-specific change becomes necessary in the reporting
// path, that contradicts MEM527/D083 and is a blocker.

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
    seedRoles();

    // Set up Safety department
    $this->safety = Department::firstOrCreate(
        ['name' => 'Safety'],
        ['description' => 'Safety and moderation department'],
    );

    // Seed the review-report tag
    Tag::firstOrCreate(
        ['name' => 'review-report'],
        ['color' => '#E11D48'],
    );

    // Seed the auto-escalation rule
    EscalationRule::firstOrCreate(
        ['name' => 'Safety Ticket Auto-Escalation'],
        [
            'description' => 'Auto-escalate Safety department tickets unresolved after 4 hours',
            'trigger_type' => 'time_based',
            'category' => 'Safety',
            'conditions' => [
                ['field' => 'department_id', 'value' => $this->safety->id],
                ['field' => 'age_hours', 'value' => 4],
            ],
            'actions' => [
                ['type' => 'escalate'],
                ['type' => 'change_priority', 'value' => 'urgent'],
            ],
            'is_active' => true,
            'order' => 10,
        ],
    );

    // Create a Platform Admin for escalation targets and notification recipients
    $this->platformAdmin = User::factory()->create(['profile_complete' => true]);
    $this->platformAdmin->assignRole('Platform Admin');

    // Create a moderation agent
    $this->agent = User::factory()->create(['profile_complete' => true]);
    $this->agent->assignRole('Service Admin');
});

// ============================================================================
// Shared helpers (assertion shape identical across game + venue review reports)
// ============================================================================

/**
 * Report a review via the generic ReportReview Livewire component and return
 * the created Safety ticket. Works for any reviewable_type.
 */
function reportReviewAndGetTicket(Review $review, User $reporter, string $reason = 'harassment'): Ticket
{
    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', $reason)
        ->call('submitReport')
        ->assertSet('successMessage', __('reviews.flash_review_reported'));

    return Ticket::where('ticket_type', 'review_report')
        ->where('requester_id', $reporter->id)
        ->firstOrFail();
}

/** Scenario 1: ticket lands in Safety, Open + High, correct type + subject. */
function assertSafetyTicketCreated(Ticket $ticket, Department $safety): void
{
    expect($ticket)->not->toBeNull();
    expect($ticket->department_id)->toBe($safety->id);
    expect($ticket->priority)->toBe(TicketPriority::High);
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->ticket_type)->toBe('review_report');
    expect($ticket->subject)->toBe('Review Report: Inappropriate');
}

/** Scenario 2: metadata carries review/author/reporter/reason + review-report tag. */
function assertTicketMetadataComplete(Ticket $ticket, Review $review, User $reviewAuthor, User $reporter, string $reason): void
{
    $metadata = $ticket->metadata;
    expect($metadata['review_id'])->toBe($review->id);
    expect($metadata['review_author_id'])->toBe($reviewAuthor->id);
    expect($metadata['report_reason'])->toBe($reason);
    expect($metadata['reporter_id'])->toBe($reporter->id);

    // Verify review-report tag applied
    expect($ticket->tags->pluck('name')->toArray())->toContain('review-report');
}

/** Scenario 3: dismiss closes the ticket and restores the review to published, body untouched. */
function dismissReportAndAssert(Ticket $ticket, Review $review, User $agent, string $expectedBody): void
{
    // Refresh review model — it was modified inside the Livewire sub-request
    $review->refresh();
    expect($review->status)->toBe('reported');

    // Simulate dismiss: close ticket, restore review to published
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $agent, 'Report dismissed by admin');
    $ticketService->close($ticket, $agent);
    $review->update(['status' => 'published']);

    $ticket->refresh();
    $review->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($review->status)->toBe('published');
    expect($review->body)->toBe($expectedBody); // content unchanged
}

/** Scenario 4: remove closes the ticket and hides the review. */
function removeReviewAndAssert(Ticket $ticket, Review $review, User $agent): void
{
    // Simulate remove: close ticket, hide review
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $agent, 'Review removed by admin');
    $ticketService->close($ticket, $agent);
    $review->update(['status' => 'hidden']);

    $ticket->refresh();
    $review->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($review->status)->toBe('hidden');
}

/** Scenario 5: escalate bumps priority to Urgent, reassigns, leaves ticket Open + review reported. */
function escalateReportAndAssert(Ticket $ticket, Review $review, User $agent, User $platformAdmin): void
{
    // Simulate escalate: add note, change priority, reassign
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $agent, "Escalated by {$agent->name}");
    $ticketService->changePriority($ticket, TicketPriority::Urgent, $agent);
    $ticket->updateQuietly(['assigned_to' => $platformAdmin->id]);

    $ticket->refresh();

    expect($ticket->priority)->toBe(TicketPriority::Urgent);
    expect($ticket->assigned_to)->toBe($platformAdmin->id);
    expect($ticket->status)->toBe(TicketStatus::Open); // still open, not closed

    // Review should remain in reported status (escalation doesn't change review)
    expect($review->fresh()->status)->toBe('reported');
}

/** Scenario 6: ReviewReported notification fires for the global admin. */
function assertReviewReportedNotificationSent(Review $review, User $reporter, User $platformAdmin): void
{
    NotificationFacade::fake();

    // Report the review — notification should be sent to platform admin
    reportReviewAndGetTicket($review, $reporter, 'harassment');

    // Verify the ReviewReported notification was sent to the global admin
    NotificationFacade::assertSentTo(
        $platformAdmin,
        ReviewReported::class,
        function ($notification) use ($review, $reporter) {
            return $notification->review->id === $review->id
                && $notification->reporter->id === $reporter->id;
        },
    );
}

/**
 * Scenario 7: ReviewObserver recomputes the owning aggregate as the review
 * status moves published → reported → published → hidden. The aggregate model
 * (GMProfile for game reviews, Location for venue reviews) and the refresh
 * callback differ per type, so they are passed in.
 *
 * @param  callable(object $aggregateModel): void  $updateAggregates
 */
function assertAggregateRecalcOnStatusChange(Review $review, User $reporter, object $aggregateModel, Closure $updateAggregates): void
{
    // Establish baseline: one published review
    $updateAggregates($aggregateModel);
    expect($aggregateModel->fresh()->review_count)->toBe(1);
    expect($aggregateModel->fresh()->average_rating)->not->toBeNull();

    // Report the review — status goes to 'reported' → observer fires
    reportReviewAndGetTicket($review, $reporter);

    // Refresh the review model (modified inside Livewire sub-request)
    $review->refresh();

    // reported reviews don't count as published, so count should be 0
    expect($aggregateModel->fresh()->review_count)->toBe(0);

    // Dismiss: restore to published → observer fires again
    $review->update(['status' => 'published']);
    expect($aggregateModel->fresh()->review_count)->toBe(1);

    // Remove: set to hidden → observer fires
    $review->update(['status' => 'hidden']);
    expect($aggregateModel->fresh()->review_count)->toBe(0);
    expect($aggregateModel->fresh()->average_rating)->toBeNull();
}

/** Scenario 8: aging a Safety ticket past the rule threshold triggers auto-escalation. */
function assertAutoEscalationFires(Ticket $ticket): void
{
    // Ticket starts as Open/High
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->priority)->toBe(TicketPriority::High);

    // Age the ticket past the 4-hour threshold
    $ticket->updateQuietly(['created_at' => now()->subHours(5)]);

    // Run escalation evaluation
    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBeGreaterThanOrEqual(1);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Escalated);
    expect($ticket->priority)->toBe(TicketPriority::Urgent);
}

// ============================================================================
// Setup: game review (reviewable_type=Game, GMProfile-linked)
// ============================================================================

/**
 * Create a game review with all required relationships: GM + GMProfile,
 * reviewer, a past game, and a published review owned by the GM profile.
 */
function createGameReviewSetup(): array
{
    $gm = User::factory()->create(['profile_complete' => true]);
    $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);
    $reviewer = User::factory()->create(['profile_complete' => true]);

    $game = Game::factory()->create([
        'owner_id' => $gm->id,
        'date_time' => now()->subDay(),
    ]);

    $review = Review::factory()->create([
        'reviewable_type' => Game::class,
        'reviewable_id' => $game->id,
        'reviewer_id' => $reviewer->id,
        'gm_profile_id' => $gmProfile->id,
        'rating' => 2,
        'body' => 'Offensive content in review',
        'status' => 'published',
    ]);

    return compact('gm', 'gmProfile', 'reviewer', 'game', 'review');
}

// ============================================================================
// Setup: venue review (reviewable_type=Location, gm_profile_id=null)
// ============================================================================

/**
 * Create a verified *commercial* venue with a published, attended venue review.
 *
 * The venue is forced to a commercial VenueType + slug so it clears the S02
 * isPublicVenuePage single authority (MEM717). The reviewer is an approved
 * participant of a completed game at the venue (MEM735), so the review is a
 * legitimate attended venue review — exactly what VenueReviews (T04) writes.
 * The observer (T03) recomputes the venue aggregate on create.
 */
function createVenueReviewSetup(): array
{
    $venue = Location::factory()->verifiedVenue()->create([
        'venue_type' => VenueType::Cafe,
        'slug' => 'test-venue-'.uniqid(),
        'name' => 'Test Venue '.uniqid(),
        'address' => '123 Test Street',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'country' => 'DEU',
    ]);

    // A completed game at the venue + the reviewer as an approved participant.
    $system = GameSystem::factory()->create();
    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create([
        'location_id' => $venue->id,
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'date_time' => now()->subDay(),
    ]);

    $reviewer = User::factory()->create(['profile_complete' => true]);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $reviewer->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Polymorphic venue review — no GM link (T01 venue() factory state).
    $review = Review::factory()->venue()->create([
        'reviewable_type' => Location::class,
        'reviewable_id' => $venue->id,
        'reviewer_id' => $reviewer->id,
        'rating' => 2,
        'body' => 'Offensive venue review content',
        'status' => 'published',
    ]);

    return compact('venue', 'reviewer', 'game', 'review');
}

// ============================================================================
// 1–8: Game review reports (reviewable_type=Game)
// ============================================================================

describe('game review reports', function () {
    it('creates an escalated ticket in Safety department with high priority when a review is reported', function () {
        ['review' => $review] = createGameReviewSetup();
        $reporter = User::factory()->create(['profile_complete' => true]);

        $ticket = reportReviewAndGetTicket($review, $reporter, 'inappropriate');

        assertSafetyTicketCreated($ticket, $this->safety);
    });

    it('stores complete review context in ticket metadata', function () {
        ['review' => $review, 'reviewer' => $reviewAuthor] = createGameReviewSetup();
        $reporter = User::factory()->create(['profile_complete' => true]);

        $ticket = reportReviewAndGetTicket($review, $reporter, 'spam');

        assertTicketMetadataComplete($ticket, $review, $reviewAuthor, $reporter, 'spam');
    });

    it('dismiss report closes ticket and keeps review published', function () {
        ['review' => $review] = createGameReviewSetup();
        $reporter = User::factory()->create(['profile_complete' => true]);

        $ticket = reportReviewAndGetTicket($review, $reporter);

        dismissReportAndAssert($ticket, $review, $this->agent, 'Offensive content in review');
    });

    it('remove review closes ticket and sets review status to hidden', function () {
        ['review' => $review] = createGameReviewSetup();
        $reporter = User::factory()->create(['profile_complete' => true]);

        $ticket = reportReviewAndGetTicket($review, $reporter);

        removeReviewAndAssert($ticket, $review, $this->agent);
    });

    it('escalate report reassigns ticket and increases priority to urgent', function () {
        ['review' => $review] = createGameReviewSetup();
        $reporter = User::factory()->create(['profile_complete' => true]);

        $ticket = reportReviewAndGetTicket($review, $reporter);

        escalateReportAndAssert($ticket, $review, $this->agent, $this->platformAdmin);
    });

    it('sends ReviewReported notification to global admins when review is reported', function () {
        ['review' => $review] = createGameReviewSetup();
        $reporter = User::factory()->create(['profile_complete' => true]);

        assertReviewReportedNotificationSent($review, $reporter, $this->platformAdmin);
    });

    it('ReviewObserver recalculates aggregates when review status changes from reported to hidden', function () {
        ['review' => $review, 'gmProfile' => $gmProfile] = createGameReviewSetup();
        $reporter = User::factory()->create(['profile_complete' => true]);

        assertAggregateRecalcOnStatusChange(
            $review,
            $reporter,
            $gmProfile,
            fn ($profile) => app(ReviewAggregateService::class)->updateAggregates($profile),
        );
    });

    it('auto-escalation rule fires on aged safety review report ticket', function () {
        ['review' => $review] = createGameReviewSetup();
        $reporter = User::factory()->create(['profile_complete' => true]);

        $ticket = reportReviewAndGetTicket($review, $reporter);

        assertAutoEscalationFires($ticket);
    });
});

// ============================================================================
// Venue review reports (reviewable_type=Location)
// Canonical pipeline regression guard + venue-specific aggregate recalc only.
// The 6 assertion-identical moderation scenarios (metadata/dismiss/remove/
// escalate/notify/auto-escalate) are covered by the game block above via the
// shared helpers, so they are not mirrored here.
// ============================================================================

describe('venue review reports', function () {
    it('creates an escalated ticket in Safety department with high priority when a venue review is reported', function () {
        ['review' => $review] = createVenueReviewSetup();
        $reporter = User::factory()->create(['profile_complete' => true]);

        $ticket = reportReviewAndGetTicket($review, $reporter, 'inappropriate');

        assertSafetyTicketCreated($ticket, $this->safety);
    });

    it('ReviewObserver recalculates venue aggregates when review status changes from reported to hidden', function () {
        ['review' => $review, 'venue' => $venue] = createVenueReviewSetup();
        $reporter = User::factory()->create(['profile_complete' => true]);

        assertAggregateRecalcOnStatusChange(
            $review,
            $reporter,
            $venue,
            fn ($location) => app(ReviewAggregateService::class)->updateLocationAggregates($location),
        );
    });
});
