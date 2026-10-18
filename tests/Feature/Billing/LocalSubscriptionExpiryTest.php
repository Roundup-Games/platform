<?php

use App\Enums\LocalSubscriptionSource;
use App\Models\LocalSubscription;
use App\Models\User;

describe('active scope truth matrix', function () {
    it('applies expiry truth to status and end date', function (string $status, ?string $endsAt, bool $expected) {
        $subscription = LocalSubscription::factory()->create([
            'status' => $status,
            'ends_at' => $endsAt === null ? null : now()->modify($endsAt),
        ]);

        $inScope = LocalSubscription::query()
            ->active()
            ->whereKey($subscription)
            ->exists();

        // isActive() must agree with scopeActive() on every cell of the matrix.
        expect($inScope)->toBe($expected)
            ->and($subscription->isActive())->toBe($expected);
    })->with([
        'active, open-ended' => ['active', null, true],
        'active, future end date' => ['active', '+30 days', true],
        'active, past end date' => ['active', '-1 day', false],
        'canceled, future end date' => ['canceled', '+30 days', false],
        'expired by sweep' => ['expired', null, false],
    ]);
});

describe('factory provenance', function () {
    it('defaults to self-serve with no grant metadata', function () {
        $subscription = LocalSubscription::factory()->create();

        expect($subscription->source)->toBe(LocalSubscriptionSource::SelfServe)
            ->and($subscription->granted_by)->toBeNull()
            ->and($subscription->reason)->toBeNull()
            ->and($subscription->isComp())->toBeFalse();
    });

    it('comp state writes comp provenance with granter and reason', function () {
        $granter = User::factory()->create();
        $subscription = LocalSubscription::factory()->comp()->create([
            'granted_by' => $granter->id,
        ]);

        expect($subscription->source)->toBe(LocalSubscriptionSource::Comp)
            ->and($subscription->isComp())->toBeTrue()
            ->and($subscription->reason)->toBeString()
            ->and($subscription->grantedBy->is($granter))->toBeTrue();
    });
});

describe('membership benefit truth', function () {
    it('keeps membership while the local subscription has not ended', function () {
        $user = User::factory()->create();
        LocalSubscription::factory()->for($user)->create();

        expect($user->hasActiveMembership())->toBeTrue();
    });

    it('drops membership once the only local subscription has ended', function () {
        $user = User::factory()->create();
        LocalSubscription::factory()->for($user)->expiredPeriod()->create();

        expect($user->hasActiveMembership())->toBeFalse();
    });
});
