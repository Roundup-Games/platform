<?php

use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Enums\EventStatus;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\Game;
use App\Models\Review;
use App\Models\User;
use App\Services\Badges\BadgeService;
use App\Services\Badges\Events\BadgeAwarded;
use App\Services\Badges\Events\BadgeUpgraded;
use App\Services\Badges\TrustedOrganizerEvaluator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event as EventFacade;

/**
 * TrustedOrganizerEvaluator (M069 S05 T4, spec §3 Events & Organizing
 * row, D206/D207): the quarterly requalifying engine — per closed
 * calendar quarter, >= 3 completed organized events, avg >= 4.5 over
 * published Game reviews on the quarter's event-linked tables, and
 * < 10% unannounced cancellations; tiers at 1/3/6 consecutive
 * qualifying quarters.
 *
 * Time is frozen to mid-quarter 2026-10-15 so quarter buckets and the
 * "open current quarter never counts" rule are deterministic: Q4 2026
 * is open, Q3 2026 the most recent closed quarter. Lapse/requalify
 * semantics ride BadgeService — these tests pin that the sweep routes
 * through it: audited rows, quiet lapses, Requalified returns.
 */

/**
 * One event the organizer ran $quartersAgo back, $daysIntoQuarter
 * days into that quarter — the fixture primitive every quarter shape
 * composes from.
 */
function trustedOrganizerEvent(User $organizer, int $quartersAgo, int $daysIntoQuarter, EventStatus $status = EventStatus::Completed): Event
{
    $quarterStart = now()->subQuarters($quartersAgo)->startOfQuarter();

    return Event::factory()->create([
        'organizer_id' => $organizer,
        'status' => $status,
        'start_date' => $quarterStart->copy()->addDays($daysIntoQuarter),
        'end_date' => $quarterStart->copy()->addDays($daysIntoQuarter + 2),
    ]);
}

/**
 * A QUALIFYING quarter $quartersAgo back: $eventCount completed
 * events spread through it plus published reviews at $ratings on a
 * table hosted at its first event (one 5/5 is the cheapest honest
 * 4.5+). Returns the quarter's final event — the expected evidence
 * pointer, since events spread by start_date.
 */
function trustedOrganizerQuarter(User $organizer, int $quartersAgo, int $eventCount = 3, array $ratings = [5]): Event
{
    $events = [];

    for ($i = 0; $i < $eventCount; $i++) {
        $events[] = trustedOrganizerEvent($organizer, $quartersAgo, 5 + 7 * $i);
    }

    $game = Game::factory()->create(['event_id' => $events[0]]);

    foreach ($ratings as $rating) {
        // gm_profile_id null: the derivation only reads
        // reviewable/status/rating, and null skips the observer's
        // GM-aggregate recompute (the venue-review shape).
        Review::factory()
            ->for(User::factory()->create(), 'reviewer')
            ->forReviewable($game)
            ->create(['rating' => $rating, 'status' => 'published', 'gm_profile_id' => null]);
    }

    return $events[count($events) - 1];
}

/**
 * One cancelled event in the quarter $quartersAgo back — silently or
 * with an EventAnnouncement, pinning both sides of criterion (c).
 */
function trustedOrganizerCancellation(User $organizer, int $quartersAgo, bool $announced, int $daysIntoQuarter = 30): Event
{
    $event = trustedOrganizerEvent($organizer, $quartersAgo, $daysIntoQuarter, EventStatus::Cancelled);

    if ($announced) {
        EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $organizer->id,
            'title' => ['en' => 'Event cancelled'],
            'content' => ['en' => 'Sorry — this event will not run after all.'],
            'is_published' => true,
        ]);
    }

    return $event;
}

/**
 * The user's trusted_organizer hold row, or null when not held.
 */
function trustedOrganizerHold(): ?BadgeUser
{
    return BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'trusted_organizer'))
        ->first();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();
});

it('pins tier thresholds to consecutive qualifying quarters', function (int $quarters, string $tier) {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $organizer = User::factory()->create();

    for ($quartersAgo = $quarters; $quartersAgo >= 1; $quartersAgo--) {
        trustedOrganizerQuarter($organizer, $quartersAgo);
    }

    $award = app(TrustedOrganizerEvaluator::class)->evaluate($organizer);

    expect($award)->not->toBeNull()
        ->and($award->badgeKey)->toBe('trusted_organizer')
        ->and($award->tierKey)->toBe($tier);
})->with([
    'one qualifying quarter → bronze' => [1, 'bronze'],
    'three consecutive quarters → silver' => [3, 'silver'],
    'six consecutive quarters → gold' => [6, 'gold'],
]);

it('awards bronze on the exact 4.5 rating boundary', function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $organizer = User::factory()->create();

    // (5 + 4) / 2 = 4.5 exactly — the rule is >=, the boundary passes.
    trustedOrganizerQuarter($organizer, 1, ratings: [5, 4]);

    expect(app(TrustedOrganizerEvaluator::class)->evaluate($organizer)?->tierKey)->toBe('bronze');
});

it('proposes nothing for a quarter with only two completed events', function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $organizer = User::factory()->create();
    trustedOrganizerQuarter($organizer, 1, eventCount: 2);

    expect(app(TrustedOrganizerEvaluator::class)->evaluate($organizer))->toBeNull();
});

it('proposes nothing when the quarter averages 4.49', function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $organizer = User::factory()->create();

    // 49 five-star and 51 four-star reviews: 449 / 100 = 4.49 — a hair
    // under the threshold the ">= 4.5" rule must reject. The
    // reviews_reviewable_unique constraint forces distinct reviewers.
    trustedOrganizerQuarter($organizer, 1, ratings: [...array_fill(0, 49, 5), ...array_fill(0, 51, 4)]);

    expect(app(TrustedOrganizerEvaluator::class)->evaluate($organizer))->toBeNull();
});

it('fail-closes a quarter whose tables carry no published reviews', function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $organizer = User::factory()->create();

    // Three completed events, tables linked, zero reviews: no rating
    // exists that could certify a 4.5 — the quarter does not qualify.
    trustedOrganizerQuarter($organizer, 1, ratings: []);

    expect(app(TrustedOrganizerEvaluator::class)->evaluate($organizer))->toBeNull();
});

it('disqualifies a quarter with a 10% unannounced-cancellation rate', function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $organizer = User::factory()->create();

    trustedOrganizerQuarter($organizer, 1, eventCount: 9);
    trustedOrganizerCancellation($organizer, quartersAgo: 1, announced: false);

    // One silent cancellation among ten events: exactly 10%, and the
    // rule is strictly below — the quarter does not qualify.
    expect(app(TrustedOrganizerEvaluator::class)->evaluate($organizer))->toBeNull();
});

it('forgives a cancellation that carried an announcement', function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $organizer = User::factory()->create();

    trustedOrganizerQuarter($organizer, 1, eventCount: 9);
    trustedOrganizerCancellation($organizer, quartersAgo: 1, announced: true);

    // Same ten events, but the cancellation was announced: the rate is
    // 0% — the quarter qualifies, bronze.
    expect(app(TrustedOrganizerEvaluator::class)->evaluate($organizer)?->tierKey)->toBe('bronze');
});

it('never counts events from the open current quarter', function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $organizer = User::factory()->create();

    trustedOrganizerQuarter($organizer, 1); // closed Q3 2026 — the only qualifying one

    // A full qualifying fixture inside Q4 2026 — the OPEN quarter:
    // without the closed-quarter rule this would stack a second
    // streak quarter and cross into silver.
    trustedOrganizerQuarter($organizer, 0);

    expect(app(TrustedOrganizerEvaluator::class)->evaluate($organizer)?->tierKey)->toBe('bronze');

    // And an organizer with ONLY current-quarter events earns nothing.
    $fresh = User::factory()->create();
    trustedOrganizerQuarter($fresh, 0);

    expect(app(TrustedOrganizerEvaluator::class)->evaluate($fresh))->toBeNull();
});

it('restarts at bronze when qualifying quarters are separated by a gap', function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $organizer = User::factory()->create();

    // Three older qualifying quarters (a silver run in isolation),
    // then a missed quarter, then one recent qualifying quarter: the
    // streak ending at Q3 2026 is exactly one — bronze, never silver.
    foreach ([4, 5, 6, 1] as $quartersAgo) {
        trustedOrganizerQuarter($organizer, $quartersAgo);
    }

    expect(app(TrustedOrganizerEvaluator::class)->evaluate($organizer)?->tierKey)->toBe('bronze');
});

it('is a pure proposal — evaluate never writes badge state', function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $organizer = User::factory()->create();
    trustedOrganizerQuarter($organizer, 1);

    app(TrustedOrganizerEvaluator::class)->evaluate($organizer);

    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('backfills the badge from one qualifying quarter with no prior state', function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $organizer = User::factory()->create();
    $latest = trustedOrganizerQuarter($organizer, 1);

    app(TrustedOrganizerEvaluator::class)->sweep();

    $hold = trustedOrganizerHold();
    $audit = BadgeEvent::query()->first();

    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBe('bronze')
        ->and($hold->evidence_type)->toBe('event')
        ->and($hold->evidence_id)->toBe((string) $latest->id)
        ->and($audit?->action)->toBe(BadgeEventAction::Awarded)
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('quietly lapses a broken streak and requalifies when the next quarter closes', function () {
    $this->travelTo(Carbon::parse('2026-01-15 12:00:00'));

    $organizer = User::factory()->create();
    trustedOrganizerQuarter($organizer, 1); // Q4 2025 — the only closed quarter

    $evaluator = app(TrustedOrganizerEvaluator::class);
    $evaluator->sweep();

    expect(trustedOrganizerHold()?->tier)->toBe('bronze');

    // April: Q1 2026 has closed with no events at all — the streak is
    // dead. Distinct second for the audit-sequence comparison below.
    $this->travel(3)->months();
    $this->travel(1)->seconds();

    EventFacade::fake([BadgeAwarded::class, BadgeUpgraded::class]);

    $evaluator->sweep();

    $hold = trustedOrganizerHold();
    $lapse = BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->first();

    // Spec §4: a broken streak greys out quietly — lapsed_at stamped,
    // row kept, one audit row with the machine-facing cause, and no
    // award/upgrade moment dispatched.
    expect($hold->lapsed_at)->not->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and($lapse)->not->toBeNull()
        ->and($lapse->reason)->toBe('streak_broken');

    EventFacade::assertNotDispatched(BadgeAwarded::class);
    EventFacade::assertNotDispatched(BadgeUpgraded::class);

    // Idempotent: a second sweep must not re-lapse or re-audit.
    $evaluator->sweep();

    expect(BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->count())->toBe(1);

    // Q1 2026 closes qualifying: the lapsed hold requalifies in place.
    $this->travel(1)->seconds();
    trustedOrganizerQuarter($organizer, 1);

    $evaluator->sweep();

    $hold = trustedOrganizerHold();
    $actions = BadgeEvent::query()->orderBy('created_at')->pluck('action')->all();

    expect($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and($hold->tier)->toBe('bronze')
        ->and($actions)->toBe([
            BadgeEventAction::Awarded,
            BadgeEventAction::Lapsed,
            BadgeEventAction::Requalified,
        ]);
});

it('sweeps current holders even when their organizing history is gone', function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    $user = User::factory()->create();
    $admin = User::factory()->create();

    app(BadgeService::class)->award(
        $user,
        'trusted_organizer',
        'bronze',
        null,
        BadgeSource::Manual,
        $admin,
        'Grandfathered before the badge engine shipped',
    );

    // No events at all: the cohort's holder arm is what pulls this
    // user in — the streak is dead on arrival and the hold lapses.
    app(TrustedOrganizerEvaluator::class)->sweep();

    expect(trustedOrganizerHold()?->lapsed_at)->not->toBeNull()
        ->and(BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->count())->toBe(1);
});
