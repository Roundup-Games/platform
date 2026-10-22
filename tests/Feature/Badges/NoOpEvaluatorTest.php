<?php

use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\User;
use App\Services\Badges\NoOpEvaluator;

/**
 * NoOpEvaluator (D186) is the standing evaluator for the next dormant
 * badge definition: it holds the registry slot so shape, tiers and copy
 * exist while the signal does not ship. Its contract — "must never
 * award, must sweep nothing" — is exactly what worth pinning before a
 * dormant definition actually relies on it.
 */
test('the dormant-slot evaluator never awards and sweeps nothing', function () {
    $user = User::factory()->create();

    expect((new NoOpEvaluator)->evaluate($user))->toBeNull();

    (new NoOpEvaluator)->sweep();

    expect(BadgeUser::count())->toBe(0)
        ->and(BadgeEvent::count())->toBe(0);
});
