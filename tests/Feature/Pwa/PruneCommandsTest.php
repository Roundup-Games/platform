<?php

use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserAppVisit;

describe('pwa:prune-stale-subscriptions', function () {
    it('dry-run reports count without deleting', function () {
        $user = User::factory()->create();

        PushSubscription::factory()->create([
            'user_id' => $user->id,
            'updated_at' => now()->subDays(200),
        ]);

        $this->artisan('pwa:prune-stale-subscriptions --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Would delete 1 stale push subscription');

        // Record should still exist
        expect(PushSubscription::count())->toBe(1);
    });

    it('deletes old subscriptions beyond max-age', function () {
        $user = User::factory()->create();

        PushSubscription::factory()->create([
            'user_id' => $user->id,
            'updated_at' => now()->subDays(200),
        ]);

        $this->artisan('pwa:prune-stale-subscriptions')
            ->assertSuccessful()
            ->expectsOutputToContain('Deleted 1 stale push subscription');

        expect(PushSubscription::count())->toBe(0);
    });

    it('keeps recent subscriptions', function () {
        $user = User::factory()->create();

        PushSubscription::factory()->create([
            'user_id' => $user->id,
            'updated_at' => now()->subDays(10),
        ]);

        $this->artisan('pwa:prune-stale-subscriptions')
            ->assertSuccessful()
            ->expectsOutputToContain('Deleted 0 stale push subscription');

        expect(PushSubscription::count())->toBe(1);
    });

    it('respects custom max-age option', function () {
        $user = User::factory()->create();

        // 30 days old — would be kept with default 180 days, deleted with --max-age=7
        PushSubscription::factory()->create([
            'user_id' => $user->id,
            'updated_at' => now()->subDays(30),
        ]);

        $this->artisan('pwa:prune-stale-subscriptions --max-age=7')
            ->assertSuccessful()
            ->expectsOutputToContain('Deleted 1 stale push subscription');

        expect(PushSubscription::count())->toBe(0);
    });

    it('rejects an invalid --max-age and preserves data instead of wiping the table', function () {
        // A recent subscription that the default policy (180d) must keep.
        $user = User::factory()->create();
        $keep = PushSubscription::factory()->create([
            'user_id' => $user->id,
            'updated_at' => now()->subDays(10),
        ]);

        // --max-age=0 / non-numeric / negative must fail fast. Without up-front
        // validation they coerce to 0: subDays(0) == now(), so `updated_at < now`
        // matches every row and delete() wipes the whole table. A destructive
        // mass-delete driven by a typo'd option is the exact data-loss class the
        // drift --limit guard was added to prevent; reject before the query runs.
        foreach (['0', 'abc', '-5', '2.5'] as $badAge) {
            $this->artisan("pwa:prune-stale-subscriptions --max-age={$badAge}")
                ->assertFailed();
        }

        // Data preserved: the recent subscription survives every invalid invocation.
        expect(PushSubscription::whereKey($keep->id)->exists())->toBeTrue();
    });
});

describe('pwa:prune-visits', function () {
    it('deletes old visit records beyond max-age', function () {
        $user = User::factory()->create();

        UserAppVisit::factory()->create([
            'user_id' => $user->id,
            'visit_date' => now()->subDays(100)->toDateString(),
        ]);

        $this->artisan('pwa:prune-visits')
            ->assertSuccessful()
            ->expectsOutputToContain('Deleted 1 old visit record');

        expect(UserAppVisit::count())->toBe(0);
    });

    it('rejects an invalid --max-age and preserves data instead of wiping the table', function () {
        // A recent visit the default policy (90d) must keep.
        $user = User::factory()->create();
        $keep = UserAppVisit::factory()->create([
            'user_id' => $user->id,
            'visit_date' => now()->subDays(10)->toDateString(),
        ]);

        // Same data-loss guard as the subscriptions command: subDays(0) matches
        // every visit_date < today and deletes the whole table.
        foreach (['0', 'abc', '-5', '2.5'] as $badAge) {
            $this->artisan("pwa:prune-visits --max-age={$badAge}")
                ->assertFailed();
        }

        expect(UserAppVisit::whereKey($keep->id)->exists())->toBeTrue();
    });
});
