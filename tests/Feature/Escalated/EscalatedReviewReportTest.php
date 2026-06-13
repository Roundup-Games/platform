<?php

use App\Livewire\Reviews\ReportReview;
use App\Models\Game;
use App\Models\GMProfile;
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

/**
 * Helper: create a reviewable review with all required relationships.
 */
function createReviewableSetup(): array
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

/**
 * Helper: report a review via Livewire and return the created ticket.
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

// ────────────────────────────────────────────────────────────────────────
// 1. Report → Ticket created in Safety department with priority=High
// ────────────────────────────────────────────────────────────────────────

it('creates an escalated ticket in Safety department with high priority when a review is reported', function () {
    ['review' => $review] = createReviewableSetup();
    $reporter = User::factory()->create(['profile_complete' => true]);

    $ticket = reportReviewAndGetTicket($review, $reporter, 'inappropriate');

    expect($ticket)->not->toBeNull();
    expect($ticket->department_id)->toBe($this->safety->id);
    expect($ticket->priority)->toBe(TicketPriority::High);
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->ticket_type)->toBe('review_report');
    expect($ticket->subject)->toBe('Review Report: Inappropriate');
});

// ────────────────────────────────────────────────────────────────────────
// 2. Ticket metadata contains review_id, author, reporter, reason
// ────────────────────────────────────────────────────────────────────────

it('stores complete review context in ticket metadata', function () {
    ['review' => $review, 'reviewer' => $reviewAuthor] = createReviewableSetup();
    $reporter = User::factory()->create(['profile_complete' => true]);

    $ticket = reportReviewAndGetTicket($review, $reporter, 'spam');

    $metadata = $ticket->metadata;
    expect($metadata['review_id'])->toBe($review->id);
    expect($metadata['review_author_id'])->toBe($reviewAuthor->id);
    expect($metadata['report_reason'])->toBe('spam');
    expect($metadata['reporter_id'])->toBe($reporter->id);

    // Verify review-report tag applied
    expect($ticket->tags->pluck('name')->toArray())->toContain('review-report');
});

// ────────────────────────────────────────────────────────────────────────
// 3. Dismiss action → ticket closed, review stays published
// ────────────────────────────────────────────────────────────────────────

it('dismiss report closes ticket and keeps review published', function () {
    ['review' => $review, 'gmProfile' => $gmProfile] = createReviewableSetup();
    $reporter = User::factory()->create(['profile_complete' => true]);

    $ticket = reportReviewAndGetTicket($review, $reporter);

    // Refresh review model — it was modified inside the Livewire sub-request
    $review->refresh();
    expect($review->status)->toBe('reported');

    // Simulate dismiss: close ticket, restore review to published
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Report dismissed by admin');
    $ticketService->close($ticket, $this->agent);
    $review->update(['status' => 'published']);

    $ticket->refresh();
    $review->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($review->status)->toBe('published');
    expect($review->body)->toBe('Offensive content in review'); // content unchanged
});

// ────────────────────────────────────────────────────────────────────────
// 4. Remove action → ticket closed, review status = hidden
// ────────────────────────────────────────────────────────────────────────

it('remove review closes ticket and sets review status to hidden', function () {
    ['review' => $review] = createReviewableSetup();
    $reporter = User::factory()->create(['profile_complete' => true]);

    $ticket = reportReviewAndGetTicket($review, $reporter);

    // Simulate remove: close ticket, hide review
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Review removed by admin');
    $ticketService->close($ticket, $this->agent);
    $review->update(['status' => 'hidden']);

    $ticket->refresh();
    $review->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($review->status)->toBe('hidden');
});

// ────────────────────────────────────────────────────────────────────────
// 5. Escalate action → ticket reassigned, priority increased
// ────────────────────────────────────────────────────────────────────────

it('escalate report reassigns ticket and increases priority to urgent', function () {
    ['review' => $review] = createReviewableSetup();
    $reporter = User::factory()->create(['profile_complete' => true]);

    $ticket = reportReviewAndGetTicket($review, $reporter);

    // Simulate escalate: add note, change priority, reassign
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, "Escalated by {$this->agent->name}");
    $ticketService->changePriority($ticket, TicketPriority::Urgent, $this->agent);
    $ticket->updateQuietly(['assigned_to' => $this->platformAdmin->id]);

    $ticket->refresh();

    expect($ticket->priority)->toBe(TicketPriority::Urgent);
    expect($ticket->assigned_to)->toBe($this->platformAdmin->id);
    expect($ticket->status)->toBe(TicketStatus::Open); // still open, not closed

    // Review should remain in reported status (escalation doesn't change review)
    expect($review->fresh()->status)->toBe('reported');
});

// ────────────────────────────────────────────────────────────────────────
// 6. ReviewReported notification still fires
// ────────────────────────────────────────────────────────────────────────

it('sends ReviewReported notification to global admins when review is reported', function () {
    NotificationFacade::fake();

    ['review' => $review] = createReviewableSetup();
    $reporter = User::factory()->create(['profile_complete' => true]);

    // Report the review — notification should be sent to platform admin
    reportReviewAndGetTicket($review, $reporter, 'harassment');

    // Verify the ReviewReported notification was sent to the global admin
    NotificationFacade::assertSentTo(
        $this->platformAdmin,
        ReviewReported::class,
        function ($notification) use ($review, $reporter) {
            return $notification->review->id === $review->id
                && $notification->reporter->id === $reporter->id;
        },
    );
});

// ────────────────────────────────────────────────────────────────────────
// 7. ReviewObserver aggregate recalculation works after status changes
// ────────────────────────────────────────────────────────────────────────

it('ReviewObserver recalculates aggregates when review status changes from reported to hidden', function () {
    ['review' => $review, 'gmProfile' => $gmProfile] = createReviewableSetup();
    $reporter = User::factory()->create(['profile_complete' => true]);

    // Establish baseline: one published review
    app(ReviewAggregateService::class)->updateAggregates($gmProfile);
    expect($gmProfile->fresh()->review_count)->toBe(1);
    expect($gmProfile->fresh()->average_rating)->not->toBeNull();

    // Report the review — status goes to 'reported' → observer fires
    reportReviewAndGetTicket($review, $reporter);

    // Refresh the review model (modified inside Livewire sub-request)
    $review->refresh();

    // reported reviews don't count as published, so count should be 0
    expect($gmProfile->fresh()->review_count)->toBe(0);

    // Dismiss: restore to published → observer fires again
    $review->update(['status' => 'published']);
    expect($gmProfile->fresh()->review_count)->toBe(1);

    // Remove: set to hidden → observer fires
    $review->update(['status' => 'hidden']);
    expect($gmProfile->fresh()->review_count)->toBe(0);
    expect($gmProfile->fresh()->average_rating)->toBeNull();
});

// ────────────────────────────────────────────────────────────────────────
// 8. Full end-to-end lifecycle: report → escalate → auto-escalate
// ────────────────────────────────────────────────────────────────────────

it('auto-escalation rule fires on aged safety review report ticket', function () {
    ['review' => $review] = createReviewableSetup();
    $reporter = User::factory()->create(['profile_complete' => true]);

    $ticket = reportReviewAndGetTicket($review, $reporter);

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
});
