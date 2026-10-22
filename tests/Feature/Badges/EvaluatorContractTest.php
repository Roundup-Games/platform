<?php

use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\Game;
use App\Models\User;
use App\Services\Badges\BadgeEvaluator;
use App\Services\Badges\BadgeRegistry;
use App\Services\Badges\CohortSweepEvaluator;
use App\Services\Badges\DebutEvaluator;

// Registry-driven evaluator contract: every registered badge's evaluator
// resolves from the container, honors the interface, and — for the pure
// half the event pipeline depends on — evaluate() NEVER writes badge
// state. The per-evaluator suites pin each badge's semantics; this file
// pins the shape guarantees no evaluator can silently drop (a new
// registry entry without a resolvable evaluator, or a proposal path
// that starts writing, fails HERE first).

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();
});

it('every registered badge has a container-resolvable evaluator honoring the contract', function () {
    $registry = app(BadgeRegistry::class);

    foreach ($registry->all() as $definition) {
        $evaluator = app($definition->evaluator);

        expect($evaluator)->toBeInstanceOf(BadgeEvaluator::class)
            ->and($definition->key)->toBeString();
    }
});

it('cohort-swept evaluators cannot bypass the skeleton — cohort() is final', function () {
    $registry = app(BadgeRegistry::class);

    foreach ($registry->all() as $definition) {
        $evaluator = app($definition->evaluator);

        if (! $evaluator instanceof CohortSweepEvaluator) {
            continue;
        }

        $method = new ReflectionMethod($evaluator, 'cohort');

        // The grouped-where invariant is the whole point of the skeleton:
        // an overridable cohort() reintroduces the chunkById infinite-
        // loop trap for every future badge author.
        expect($method->isFinal())->toBeTrue();
    }
});

it('evaluate() is pure for every badge — no hold rows, no audit rows', function () {
    $registry = app(BadgeRegistry::class);
    $user = User::factory()->create();

    // A user with a GM profile and a guild-less, membership-less state:
    // most evaluators return null; any non-null result is fine — what is
    // pinned is that NOTHING is written either way.
    foreach ($registry->all() as $definition) {
        app($definition->evaluator)->evaluate($user);
    }

    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('evaluate() is pure even for a user who qualifies — a proposal is not an outcome', function () {
    // Debut is the cheapest deterministic qualifier: an owned past game.
    $owner = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now()->subDay(),
    ]);

    $award = app(DebutEvaluator::class)->evaluate($owner);

    expect($award)->not->toBeNull()
        ->and($award->badgeKey)->toBe('debut')
        ->and(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0)
        ->and($game->exists())->toBeTrue(); // untouched — no writes anywhere
});
