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
});
