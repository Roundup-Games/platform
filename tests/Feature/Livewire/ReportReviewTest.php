<?php

use App\Livewire\Reviews\ReportReview;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;


beforeEach(function () {
    URL::defaults(['locale' => 'en']);
    seedRoles();
});

function createReportableReview(): array
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
        'rating' => 4,
        'body' => 'Great session!',
        'status' => 'published',
    ]);

    return compact('gm', 'gmProfile', 'reviewer', 'game', 'review');
}

it('can open and close the report modal', function () {
    ['review' => $review] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->assertSet('showModal', false)
        ->call('openModal')
        ->assertSet('showModal', true)
        ->call('closeModal')
        ->assertSet('showModal', false);
});

it('can submit a report with valid reason', function () {
    ['review' => $review] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', 'inappropriate')
        ->call('submitReport')
        ->assertSet('showModal', false)
        ->assertSet('successMessage', __('reviews.flash_review_reported'));

    expect($review->fresh())
        ->status->toBe('reported')
        ->reported_by->toBe($reporter->id)
        ->report_reason->toBe('inappropriate')
        ->reported_at->not->toBeNull();
});

it('validates reason is required', function () {
    ['review' => $review] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->call('submitReport')
        ->assertHasErrors(['reason' => 'required']);
});

it('validates reason is a valid enum value', function () {
    ['review' => $review] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', 'invalid_reason')
        ->call('submitReport')
        ->assertHasErrors(['reason' => 'in']);
});

it('accepts all valid report reasons', function ($reason) {
    ['review' => $review] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', $reason)
        ->call('submitReport')
        ->assertSet('successMessage', __('reviews.flash_review_reported'));

    expect($review->fresh()->report_reason)->toBe($reason);
})->with(['inappropriate', 'spam', 'harassment', 'other']);

it('prevents reporting an already reported review', function () {
    ['review' => $review] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);

    // First report
    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', 'spam')
        ->call('submitReport');

    // Second report attempt
    $otherReporter = User::factory()->create(['profile_complete' => true]);
    Livewire::actingAs($otherReporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', 'harassment')
        ->call('submitReport')
        ->assertHasErrors(['reason']);
});

it('prevents reviewer from reporting their own review', function () {
    ['review' => $review, 'reviewer' => $reviewer] = createReportableReview();

    Livewire::actingAs($reviewer)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', 'spam')
        ->call('submitReport');

    // Review should still be published (not reported)
    expect($review->fresh()->status)->toBe('published');
});

it('handles non-existent review gracefully', function () {
    $reporter = User::factory()->create(['profile_complete' => true]);
    $fakeId = (string) \Illuminate\Support\Str::uuid();

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $fakeId])
        ->set('reason', 'spam')
        ->call('submitReport')
        ->assertHasErrors(['reason']);
});

// ── Review model unit tests ────────────────────────────

it('review report method sets all fields correctly', function () {
    ['review' => $review] = createReportableReview();
    $reporter = User::factory()->create();

    $review->report($reporter->id, 'harassment');

    $fresh = $review->fresh();
    expect($fresh->status)->toBe('reported');
    expect($fresh->reported_by)->toBe($reporter->id);
    expect($fresh->report_reason)->toBe('harassment');
    expect($fresh->reported_at)->not->toBeNull();
});

it('review report method triggers aggregate recalculation', function () {
    ['review' => $review, 'gmProfile' => $gmProfile] = createReportableReview();

    // Recalculate to establish baseline
    app(\App\Services\ReviewAggregateService::class)->updateAggregates($gmProfile);
    expect($gmProfile->fresh()->review_count)->toBe(1);

    // Report the review — should trigger observer and reduce published count
    $reporter = User::factory()->create();
    $review->report($reporter->id, 'spam');

    // Observer should have fired and updated aggregates (no published reviews now)
    expect($gmProfile->fresh()->review_count)->toBe(0);
    expect($gmProfile->fresh()->average_rating)->toBeNull();
});

// ── Safety ticket creation tests ───────────────────────

if (! function_exists('seedSafetyDepartment')) {
    function seedSafetyDepartment(): void
    {
        Department::firstOrCreate(
            ['name' => 'Safety'],
            ['description' => 'Review reports, content moderation, user reports', 'is_active' => true],
        );
    }
}

if (! function_exists('seedReviewReportTag')) {
    function seedReviewReportTag(): void
    {
        Tag::firstOrCreate(
            ['name' => 'review-report'],
            ['color' => '#E11D48'],
        );
    }
}

if (! function_exists('seedReportReviewSetup')) {
    function seedReportReviewSetup(): void
    {
        seedSafetyDepartment();
        seedReviewReportTag();
    }
}

it('creates a safety ticket when a review is reported', function () {
    seedReportReviewSetup();

    ['review' => $review, 'reviewer' => $reviewAuthor] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', 'harassment')
        ->call('submitReport')
        ->assertSet('successMessage', __('reviews.flash_review_reported'));

    // Verify ticket was created
    $ticket = Ticket::where('ticket_type', 'review_report')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->subject)->toBe('Review Report: Harassment');
    expect($ticket->priority)->toBe(TicketPriority::High);
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->requester_id)->toBe($reporter->id);
    expect($ticket->requester_type)->toBe(User::class);

    // Verify metadata
    $metadata = $ticket->metadata;
    expect($metadata['review_id'])->toBe($review->id);
    expect($metadata['review_author_id'])->toBe($reviewAuthor->id);
    expect($metadata['report_reason'])->toBe('harassment');
    expect($metadata['reporter_id'])->toBe($reporter->id);

    // Verify department
    $department = Department::where('name', 'Safety')->first();
    expect($ticket->department_id)->toBe($department->id);

    // Verify tag
    expect($ticket->tags->pluck('name')->toArray())->toContain('review-report');
});

it('creates safety ticket even when safety department does not exist', function () {
    // Do NOT seed the Safety department — ticket should still be created
    seedReviewReportTag();

    ['review' => $review] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', 'spam')
        ->call('submitReport')
        ->assertSet('successMessage', __('reviews.flash_review_reported'));

    // Ticket created with null department_id (graceful degradation)
    $ticket = Ticket::where('ticket_type', 'review_report')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->department_id)->toBeNull();
    expect($ticket->priority)->toBe(TicketPriority::High);
});

it('includes review author name in ticket description', function () {
    seedReportReviewSetup();

    ['review' => $review, 'reviewer' => $reviewAuthor] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', 'inappropriate')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'review_report')->first();
    expect($ticket->description)->toContain($reviewAuthor->name);
    expect($ticket->description)->toContain('Inappropriate');
    expect($ticket->description)->toContain('Great session!');
    expect($ticket->description)->toContain('4/5');
});
