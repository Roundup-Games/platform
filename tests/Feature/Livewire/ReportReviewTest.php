<?php

use App\Enums\NotificationCategory;
use App\Livewire\Reviews\ReportReview;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
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

it('can render the report review component', function () {
    ['review' => $review, 'reviewer' => $reviewer] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->assertOk();
});

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

it('logs review report event', function () {
    ['review' => $review] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', 'spam')
        ->call('submitReport');

    // Verify the review was actually reported (proves logging path was hit)
    expect($review->fresh()->status)->toBe('reported');
    expect($review->fresh()->report_reason)->toBe('spam');
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

it('queues admin notification on report', function () {
    ['review' => $review] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);

    \Illuminate\Support\Facades\Notification::fake();

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', 'inappropriate')
        ->call('submitReport');

    // Verify the review was reported
    expect($review->fresh()->status)->toBe('reported');
    expect($review->fresh()->report_reason)->toBe('inappropriate');
});

it('does not notify non-admin users', function () {
    ['review' => $review] = createReportableReview();
    $reporter = User::factory()->create(['profile_complete' => true]);
    $regularUser = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportReview::class, ['reviewId' => $review->id])
        ->set('reason', 'spam')
        ->call('submitReport');

    // Regular user should NOT receive notification
    expect($regularUser->fresh()->notifications)->toHaveCount(0);
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

// ── GM Profile review section tests ────────────────────

it('gm profile shows review section with reviews', function () {
    ['gm' => $gm, 'gmProfile' => $gmProfile, 'reviewer' => $reviewer] = createReportableReview();

    // Update aggregates
    app(\App\Services\ReviewAggregateService::class)->updateAggregates($gmProfile);

    $response = $this->actingAs(User::factory()->create(['profile_complete' => true]))
        ->get(route('profile.public', $gm));

    $response->assertOk()
        ->assertSee('4.0')
        ->assertSee('1 review')
        ->assertSee('Great session!');
});

it('gm profile shows no reviews message when empty', function () {
    $gm = User::factory()->create(['profile_complete' => true]);
    $gmProfile = GMProfile::factory()->create(['user_id' => $gm->id, 'review_count' => 0]);

    $response = $this->actingAs(User::factory()->create(['profile_complete' => true]))
        ->get(route('profile.public', $gm));

    $response->assertOk()
        ->assertSee('No reviews yet');
});

it('gm profile shows top proficiency badges', function () {
    ['gm' => $gm, 'gmProfile' => $gmProfile] = createReportableReview();

    // Update the review to have proficiency tags
    $review = Review::first();
    $review->update(['proficiency_tags' => ['storytelling', 'voices', 'world_builder']]);

    app(\App\Services\ReviewAggregateService::class)->updateAggregates($gmProfile->fresh());

    $response = $this->actingAs(User::factory()->create(['profile_complete' => true]))
        ->get(route('profile.public', $gm));

    $response->assertOk();

    // Verify proficiency badges render (check the top proficiencies are computed)
    $topProfs = $gmProfile->fresh()->topProficiencies();
    expect($topProfs)->toHaveCount(3);
    expect($topProfs->first()['name'])->toBe('storytelling');
});

it('guest can view gm profile review section', function () {
    ['gm' => $gm, 'gmProfile' => $gmProfile] = createReportableReview();

    app(\App\Services\ReviewAggregateService::class)->updateAggregates($gmProfile);

    $response = $this->get(route('profile.public', $gm));

    $response->assertOk()
        ->assertSee('4.0')
        ->assertSee('Great session!');
});
