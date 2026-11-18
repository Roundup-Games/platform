<?php

use App\Enums\LocalSubscriptionSource;
use App\Models\MembershipType;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * The per-source partial unique index (R083) — one active subscription per
 * (user, plan, source) — now ships in the squashed schema baseline as
 * local_subscriptions_one_active_per_source_unique.
 *
 * The original harness here drove the one-time migration object directly
 * (down() → seed duplicates → up() → assert the dedupe pass); the migration
 * file was squashed into the schema baseline (#28), so that rollout story
 * has no subject left. The durable invariant is the constraint itself,
 * asserted against the live schema: a second ACTIVE row on the same key is
 * rejected structurally, while distinct sources and unlimited non-active
 * history remain legal (the index is partial on status).
 */
beforeEach(function () {
    MembershipType::updateOrCreate(
        ['name' => 'Game Master'],
        [
            'description' => 'Test GM plan (unique index fixtures)',
            'price_cents' => 0,
            'duration_months' => 0,
            'status' => 'active',
            'type' => 'local',
            'paddle_price_id' => null,
            'metadata' => ['gm_plan' => true],
        ],
    );
});

it('rejects a second active subscription on the same (user, plan, source)', function () {
    $user = User::factory()->create();
    $plan = MembershipType::where('metadata->gm_plan', true)->firstOrFail();

    $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => null,
        'source' => LocalSubscriptionSource::SelfServe,
    ]);

    expect($user->localSubscriptions()->active()->count())->toBe(1)
        ->and(fn () => $user->localSubscriptions()->create([
            'membership_type_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => null,
            'source' => LocalSubscriptionSource::SelfServe,
        ]))->toThrow(QueryException::class);
});

it('allows distinct sources to coexist and non-active history to repeat', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $plan = MembershipType::where('metadata->gm_plan', true)->firstOrFail();

    // Comp + self-serve coexistence is legal (different source)…
    $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id, 'status' => 'active',
        'starts_at' => now(), 'ends_at' => null,
        'source' => LocalSubscriptionSource::SelfServe,
    ]);
    $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id, 'status' => 'active',
        'starts_at' => now(), 'ends_at' => null,
        'source' => LocalSubscriptionSource::Comp,
        'granted_by' => $other->id, 'reason' => 'ops comp',
    ]);

    // …and so is unlimited canceled history (the index is partial).
    $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id, 'status' => 'canceled',
        'starts_at' => now()->subYear(), 'ends_at' => null,
        'canceled_at' => now()->subMonths(6),
        'source' => LocalSubscriptionSource::SelfServe,
    ]);

    expect($user->localSubscriptions()->active()->count())->toBe(2)
        ->and($user->localSubscriptions()->where('status', 'canceled')->count())->toBe(1);
});
