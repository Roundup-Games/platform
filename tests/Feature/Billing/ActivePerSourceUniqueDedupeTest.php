<?php

use App\Enums\LocalSubscriptionSource;
use App\Models\MembershipType;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * The per-source partial unique index migration (R083 backstop):
 * the index creation must be preceded by a dedupe pass, because the
 * races the migration itself documents (lookup-then-write with no
 * prior constraint) could have left duplicate ACTIVE rows in any
 * environment that ran the pre-M069 writers — and `create unique
 * index` aborts on the first duplicate, mid-deploy.
 *
 * The migration object is driven directly (down() → duplicates →
 * up()) inside the test transaction: Postgres DDL is transactional,
 * so the index flip rolls back with the test.
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

it('dedupes pre-existing active duplicates and keeps the newest row active', function () {
    $user = User::factory()->create();
    $plan = MembershipType::where('metadata->gm_plan', true)->firstOrFail();
    $migration = require database_path('migrations/2026_10_21_010000_add_active_per_source_unique_to_local_subscriptions.php');

    // Simulate the pre-migration world: the constraint does not exist
    // yet, so the documented lookup-then-write races could leave TWO
    // active rows on the same (user, plan, source) key.
    $migration->down();

    $older = $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now()->subMonths(2),
        'ends_at' => null,
        'source' => LocalSubscriptionSource::SelfServe,
    ]);
    $newer = $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now()->subMonth(),
        'ends_at' => null,
        'source' => LocalSubscriptionSource::SelfServe,
    ]);

    $migration->up();

    expect($older->fresh()->status)->toBe('canceled')
        ->and($older->fresh()->canceled_at)->not->toBeNull()
        ->and($newer->fresh()->status)->toBe('active')
        ->and($user->localSubscriptions()->active()->count())->toBe(1);
});

it('leaves distinct sources and inactive history untouched', function () {
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
    // …and so is unlimited canceled history (partial index).
    $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id, 'status' => 'canceled',
        'starts_at' => now()->subYear(), 'ends_at' => null,
        'canceled_at' => now()->subMonths(6),
        'source' => LocalSubscriptionSource::SelfServe,
    ]);

    $migration = require database_path('migrations/2026_10_21_010000_add_active_per_source_unique_to_local_subscriptions.php');
    $migration->down();
    $migration->up();

    expect($user->localSubscriptions()->active()->count())->toBe(2)
        ->and($user->localSubscriptions()->where('status', 'canceled')->count())->toBe(1);
});

it('enforces one active row per (user, plan, source) structurally after up()', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $plan = MembershipType::where('metadata->gm_plan', true)->firstOrFail();

    $user->localSubscriptions()->create([
        'membership_type_id' => $plan->id, 'status' => 'active',
        'starts_at' => now(), 'ends_at' => null,
        'source' => LocalSubscriptionSource::SelfServe,
    ]);

    $migration = require database_path('migrations/2026_10_21_010000_add_active_per_source_unique_to_local_subscriptions.php');
    $migration->down();
    $migration->up();

    try {
        $user->localSubscriptions()->create([
            'membership_type_id' => $plan->id, 'status' => 'active',
            'starts_at' => now(), 'ends_at' => null,
            'source' => LocalSubscriptionSource::SelfServe,
        ]);

        $this->fail('Expected the partial unique index to reject the duplicate active row.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23505');
    }
});
