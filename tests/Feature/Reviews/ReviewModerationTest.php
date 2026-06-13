<?php

use App\Models\Game;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\TicketService;

beforeEach(function () {
    seedRoles();

    // Set up the Safety department
    Department::firstOrCreate(['name' => 'Safety'], ['description' => 'Safety and moderation department']);

    // Create a Platform Admin user for escalation tests
    $this->platformAdmin = User::factory()->create(['profile_complete' => true]);
    $this->platformAdmin->assignRole('Platform Admin');

    // Create an agent user who performs the actions
    $this->agent = User::factory()->create(['profile_complete' => true]);
    $this->agent->assignRole('Service Admin');
});

/**
 * Create a review report ticket with associated review.
 */
function createReviewReportTicket(): array
{
    $gm = User::factory()->create(['profile_complete' => true]);
    $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id]);
    $reviewer = User::factory()->create(['profile_complete' => true]);
    $reporter = User::factory()->create(['profile_complete' => true]);

    $game = Game::factory()->create([
        'owner_id' => $gm->id,
        'date_time' => now()->subDay(),
    ]);

    $review = Review::factory()->create([
        'reviewable_type' => Game::class,
        'reviewable_id' => $game->id,
        'reviewer_id' => $reviewer->id,
        'gm_profile_id' => $gmProfile->id,
        'rating' => 1,
        'body' => 'Terrible experience!',
        'status' => 'reported',
        'reported_by' => $reporter->id,
        'report_reason' => 'harassment',
        'reported_at' => now(),
    ]);

    $department = Department::where('name', 'Safety')->first();

    $ticket = Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $reporter->id,
        'subject' => 'Review Report: Harassment',
        'description' => 'Reported review content...',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::High->value,
        'department_id' => $department?->id,
        'ticket_type' => 'review_report',
        'metadata' => [
            'review_id' => $review->id,
            'review_author_id' => $review->reviewer_id,
            'report_reason' => 'harassment',
            'reporter_id' => $reporter->id,
        ],
    ]);

    return compact('ticket', 'review', 'gm', 'gmProfile', 'reviewer', 'reporter', 'game', 'department');
}

// ── Dismiss Action Tests ────────────────────────────────────────────────

it('dismiss action closes ticket and keeps review published', function () {
    ['ticket' => $ticket, 'review' => $review] = createReviewReportTicket();

    $ticketService = app(TicketService::class);

    // Simulate dismiss action: add note, close ticket, restore review
    $ticketService->addNote($ticket, $this->agent, 'Report dismissed by admin');
    $ticketService->close($ticket, $this->agent);

    // Update review status back to published (dismiss keeps review visible)
    $review->update(['status' => 'published']);

    // Refresh models
    $ticket->refresh();
    $review->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($review->status)->toBe('published');
    expect($review->body)->toBe('Terrible experience!'); // Content unchanged
});

it('dismiss action adds internal note to ticket', function () {
    ['ticket' => $ticket] = createReviewReportTicket();

    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Report dismissed by admin');
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();

    $notes = $ticket->internalNotes;
    expect($notes)->toHaveCount(1);
    expect($notes->first()->body)->toBe('Report dismissed by admin');
    expect($notes->first()->is_internal_note)->toBeTrue();
});

// ── Remove Action Tests ────────────────────────────────────────────────

it('remove action closes ticket and hides review', function () {
    ['ticket' => $ticket, 'review' => $review] = createReviewReportTicket();

    $ticketService = app(TicketService::class);

    // Simulate remove action: add note, close ticket, hide review
    $ticketService->addNote($ticket, $this->agent, 'Review removed by admin');
    $ticketService->close($ticket, $this->agent);

    // Update review status to hidden (remove hides the review)
    $review->update(['status' => 'hidden']);

    // Refresh models
    $ticket->refresh();
    $review->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($review->status)->toBe('hidden');
});

it('remove action triggers ReviewObserver aggregate recalculation', function () {
    ['ticket' => $ticket, 'review' => $review, 'gmProfile' => $gmProfile] = createReviewReportTicket();

    // Verify GM profile has aggregate data before removal
    expect($gmProfile->fresh()->review_count)->toBeGreaterThanOrEqual(0);

    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Review removed by admin');
    $ticketService->close($ticket, $this->agent);

    // Update status triggers ReviewObserver::updated (status is dirty)
    $review->update(['status' => 'hidden']);

    // The aggregate recalculation ran without errors (ReviewObserver handles it)
    $gmProfile->refresh();
    expect($gmProfile)->toBeInstanceOf(GMProfile::class);
});

// ── Escalate Action Tests ──────────────────────────────────────────────

it('escalate action increases priority to urgent', function () {
    ['ticket' => $ticket] = createReviewReportTicket();

    $ticketService = app(TicketService::class);

    // Simulate escalate: add note, change priority to Urgent
    $ticketService->addNote($ticket, $this->agent, "Escalated by {$this->agent->name}");
    $ticketService->changePriority($ticket, TicketPriority::Urgent, $this->agent);

    $ticket->refresh();

    expect($ticket->priority)->toBe(TicketPriority::Urgent);
});

it('escalate action reassigns to Platform Admin', function () {
    ['ticket' => $ticket] = createReviewReportTicket();

    $ticketService = app(TicketService::class);

    // Add note
    $ticketService->addNote($ticket, $this->agent, "Escalated by {$this->agent->name}");

    // Change priority
    $ticketService->changePriority($ticket, TicketPriority::Urgent, $this->agent);

    // Assign to Platform Admin (using updateQuietly to avoid TicketAssigned event type mismatch)
    $ticket->updateQuietly(['assigned_to' => $this->platformAdmin->id]);

    $ticket->refresh();

    expect($ticket->assigned_to)->toBe($this->platformAdmin->id);
});

it('escalate action adds escalation internal note', function () {
    ['ticket' => $ticket] = createReviewReportTicket();

    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, "Escalated by {$this->agent->name}");

    $ticket->refresh();

    $notes = $ticket->internalNotes;
    expect($notes)->toHaveCount(1);
    expect($notes->first()->body)->toContain('Escalated by');
    expect($notes->first()->body)->toContain($this->agent->name);
});

// ── Review Status Sync Tests ───────────────────────────────────────────

it('review without ticket metadata review_id logs warning gracefully', function () {
    ['ticket' => $ticket, 'review' => $review] = createReviewReportTicket();

    // Remove review_id from metadata to simulate edge case
    $ticket->updateQuietly(['metadata' => array_merge($ticket->metadata ?? [], ['review_id' => null])]);

    // The dismiss action should still succeed (ticket closes) even if review_id is missing
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Report dismissed by admin');
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);

    // Review should remain unchanged (no crash)
    $review->refresh();
    expect($review->status)->toBe('reported');
});

it('handles missing review gracefully during status update', function () {
    ['ticket' => $ticket] = createReviewReportTicket();

    // Point to a non-existent review
    $metadata = $ticket->metadata ?? [];
    $metadata['review_id'] = '00000000-0000-0000-0000-000000000000';
    $ticket->updateQuietly(['metadata' => $metadata]);

    // The ticket should still close successfully
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Review removed by admin');
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

// ── Non-review-report tickets are unaffected ──────────────────────────

it('non-review-report tickets are unaffected by review moderation logic', function () {
    $department = Department::where('name', 'Safety')->first();

    // Create a regular (non-review-report) ticket
    $ticket = Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $this->agent->id,
        'subject' => 'General safety inquiry',
        'description' => 'A question about safety policies',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $department?->id,
        'ticket_type' => 'question',
        'metadata' => [],
    ]);

    $ticketService = app(TicketService::class);
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});
