<?php

use App\Enums\LocalSubscriptionSource;
use App\Models\BadgeUser;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\Badges\SupportingMemberEvaluator;

/**
 * SupportingMemberEvaluator (spec §2.5): tenure tiers off the earliest
 * membership start. The derivation under test: an active membership
 * alone earns year_1; completed months (12/24/60) raise the tier; and
 * a future starts_at — reachable only on hand-crafted/admin rows, since
 * every writer stamps now() — must count as ZERO elapsed months, not
 * (as abs() would have it) the distance until the start.
 */
beforeEach(function () {
    MembershipType::updateOrCreate(
        ['name' => 'Game Master'],
        [
            'description' => 'Test GM plan (supporting member fixtures)',
            'price_cents' => 0,
            'duration_months' => 0,
            'status' => 'active',
            'type' => 'local',
            'paddle_price_id' => null,
            'metadata' => ['gm_plan' => true],
        ],
    );
});

function supportingMemberSub(User $user, string $source, ?string $startsAt, ?string $endsAt = null): void
{
    $user->localSubscriptions()->create([
        'membership_type_id' => MembershipType::where('metadata->gm_plan', true)->firstOrFail()->id,
        'status' => 'active',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'source' => $source,
    ]);
}

it('proposes year_1 for a brand-new active membership', function () {
    $user = User::factory()->create();
    supportingMemberSub($user, LocalSubscriptionSource::SelfServe->value, now()->toDateTimeString());

    $award = app(SupportingMemberEvaluator::class)->evaluate($user);

    expect($award)->not->toBeNull()
        ->and($award->badgeKey)->toBe('supporting_member')
        ->and($award->tierKey)->toBe('year_1');
});

it('proposes the tier the completed months have earned', function (string $startsAt, string $expectedTier) {
    $user = User::factory()->create();
    supportingMemberSub($user, LocalSubscriptionSource::SelfServe->value, $startsAt);

    expect(app(SupportingMemberEvaluator::class)->evaluate($user)?->tierKey)->toBe($expectedTier);
})->with([
    'twelve completed months' => [now()->subMonths(12)->subDay()->toDateTimeString(), 'year_2'],
    'twenty-four completed months' => [now()->subMonths(24)->subDay()->toDateTimeString(), 'year_3'],
    'eleven months stays year_1' => [now()->subMonths(11)->addDay()->toDateTimeString(), 'year_1'],
]);

it('anchors tenure on the EARLIEST start across subscription rows', function () {
    $user = User::factory()->create();
    // The current self-serve row is young, but support history from an
    // older comp row keeps the months (rejoiners keep their tenure).
    supportingMemberSub($user, LocalSubscriptionSource::Comp->value, now()->subMonths(13)->toDateTimeString());
    supportingMemberSub($user, LocalSubscriptionSource::SelfServe->value, now()->toDateTimeString());

    expect(app(SupportingMemberEvaluator::class)->evaluate($user)?->tierKey)->toBe('year_2');
});

it('clamps a future starts_at to zero elapsed months instead of abs()-flipping it', function () {
    $user = User::factory()->create();
    // Admin-crafted row: starts in 13 months but active. The months
    // until the start are NOT tenure — year_1, never year_2.
    supportingMemberSub($user, LocalSubscriptionSource::Comp->value, now()->addMonths(13)->toDateTimeString());

    expect(app(SupportingMemberEvaluator::class)->evaluate($user)?->tierKey)->toBe('year_1');
});

it('excludes anonymized accounts from the sweep cohort even with a qualifying membership', function () {
    // The overridden baseQuery() composes parent::baseQuery() precisely
    // so the notAnonymized freeze survives the eager-load override:
    // erasure must mean no new badge rows or notifications, ever.
    $erased = User::factory()->create();
    $erased->forceFill(['anonymized_at' => now()])->save();
    supportingMemberSub($erased, LocalSubscriptionSource::SelfServe->value, now()->toDateTimeString());

    $active = User::factory()->create();
    supportingMemberSub($active, LocalSubscriptionSource::SelfServe->value, now()->toDateTimeString());

    $this->artisan('badges:sync')->assertSuccessful();
    app(SupportingMemberEvaluator::class)->sweep();

    $holds = static fn (User $user) => BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'supporting_member'))
        ->where('user_id', $user->id)
        ->count();

    expect($holds($erased))->toBe(0)
        ->and($holds($active))->toBe(1)
        ->and($erased->notifications()->count())->toBe(0);
});

it('proposes nothing without an active membership', function () {
    $user = User::factory()->create();
    supportingMemberSub($user, LocalSubscriptionSource::SelfServe->value, now()->subYear()->toDateTimeString(), now()->subDay()->toDateTimeString());

    expect(app(SupportingMemberEvaluator::class)->evaluate($user))->toBeNull();
});
