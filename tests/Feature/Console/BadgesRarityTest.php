<?php

use App\Enums\BadgeRarity;
use App\Models\BadgeDefinition;
use App\Models\BadgeUser;
use App\Models\User;
use App\Services\Badges\BadgesRarity as BadgesRarityService;
use App\Support\Scheduling\TaskIdentity;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * badges:rarity (M069 S04 T1, D195): the deferred second half of the
 * nightly badge jobs — recompute the job-owned holders/eligible counts
 * and the rarity tier on every badge_definitions row, with per-definition
 * failure isolation and a 06:45 schedule slot fifteen minutes after the
 * 06:30 sweep so counts reflect that night's awards.
 *
 * Helpers are top-level with a badgesRarity- prefix: sibling Console
 * tests share the global namespace.
 */

/**
 * Fresh copy of one synced definition by registry key.
 */
function badgesRarityDefinition(string $key): BadgeDefinition
{
    return BadgeDefinition::query()->where('key', $key)->sole();
}

/**
 * Award a badge hold to a user — a factory fixture, not the pipeline:
 * the rarity job only reads badge_user rows, so the write path is
 * irrelevant to what is under test here.
 *
 * @param  array<string, mixed>  $attributes
 */
function badgesRarityAward(BadgeDefinition $definition, User $user, array $attributes = []): BadgeUser
{
    return BadgeUser::factory()->create(array_merge([
        'user_id' => $user,
        'badge_definition_id' => $definition,
    ], $attributes));
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();

    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);
});

it('maps holder percentages onto rarity tiers at inclusive boundaries', function (float $percentage, BadgeRarity $rarity) {
    expect(BadgeRarity::fromPercentage($percentage))->toBe($rarity);
})->with([
    '60% exactly → common (lower edge inclusive)' => [60.0, BadgeRarity::Common],
    '59.9% → uncommon (just under common)' => [59.9, BadgeRarity::Uncommon],
    '20% exactly → uncommon' => [20.0, BadgeRarity::Uncommon],
    '19.9% → rare (just under uncommon)' => [19.9, BadgeRarity::Rare],
    '5% exactly → rare' => [5.0, BadgeRarity::Rare],
    '4.9% → elite (just under rare)' => [4.9, BadgeRarity::Elite],
    '1% exactly → elite' => [1.0, BadgeRarity::Elite],
    '0.9% → legendary (just under elite)' => [0.9, BadgeRarity::Legendary],
    '0% → legendary (nobody holds it)' => [0.0, BadgeRarity::Legendary],
]);

it('computes counts and rarity classes across the five tier boundaries in one pass', function () {
    // 100 eligible users make every tier boundary land on an exact
    // percentage: 60/20/5/1/0 across the five all-users definitions.
    $users = User::factory()->count(100)->create();

    $expectedTiers = [
        'debut' => [60, BadgeRarity::Common],
        'first_seat' => [20, BadgeRarity::Uncommon],
        'founding_table' => [5, BadgeRarity::Rare],
        'supporting_member' => [1, BadgeRarity::Elite],
        'regulars_table' => [0, BadgeRarity::Legendary],
    ];

    foreach ($expectedTiers as $key => [$holderCount]) {
        $definition = badgesRarityDefinition($key);

        foreach ($users->take($holderCount) as $user) {
            badgesRarityAward($definition, $user);
        }
    }

    $this->artisan('badges:rarity')->assertSuccessful();

    foreach ($expectedTiers as $key => [$holderCount, $rarity]) {
        $definition = badgesRarityDefinition($key)->refresh();

        expect($definition->holders_count)->toBe($holderCount)
            ->and($definition->eligible_count)->toBe(100)
            ->and($definition->rarity_class)->toBe($rarity)
            ->and($definition->rarity_computed_at)->not->toBeNull();
    }
});

it('counts lapsed holds but excludes revoked ones', function () {
    $definition = badgesRarityDefinition('debut');
    [$active, $lapsed, $revoked] = User::factory()->count(3)->create();

    badgesRarityAward($definition, $active);
    // A lapsed badge is still held-earned (spec principle 4: greyed out,
    // never vanished) — it keeps counting. Only revocation removes it.
    badgesRarityAward($definition, $lapsed, ['lapsed_at' => now()]);
    badgesRarityAward($definition, $revoked, ['revoked_at' => now()]);

    $this->artisan('badges:rarity')->assertSuccessful();

    $definition->refresh();

    expect($definition->holders_count)->toBe(2)
        ->and($definition->eligible_count)->toBe(3)
        // 2/3 ≈ 66.7% → common.
        ->and($definition->rarity_class)->toBe(BadgeRarity::Common);
});

it('scopes the game master denominator to non-anonymized GMs only', function () {
    $players = User::factory()->count(10)->create();

    $gms = User::factory()->count(4)->create();
    $gms->each(fn (User $gm) => $gm->assignRole('Game Master'));

    // An anonymized GM: role still attached, account deleted — out of
    // the denominator (MEM586), exactly as for the all-users pool.
    $anonymizedGm = User::factory()->create();
    $anonymizedGm->assignRole('Game Master');
    $anonymizedGm->forceFill(['anonymized_at' => now()])->saveQuietly();

    $chartered = badgesRarityDefinition('chartered');
    badgesRarityAward($chartered, $gms[0]);
    badgesRarityAward($chartered, $gms[1]);

    $this->artisan('badges:rarity')->assertSuccessful();

    $chartered->refresh();

    // GM-craft badge: 2 holders over the 4 non-anonymized GMs → 50%,
    // an "uncommon" GM badge — not 2/14 mis-graded against all users.
    expect($chartered->eligible_count)->toBe(4)
        ->and($chartered->holders_count)->toBe(2)
        ->and($chartered->rarity_class)->toBe(BadgeRarity::Uncommon);

    // The all-users denominator counts every non-anonymized user
    // (10 players + 4 GMs); the anonymized GM is excluded there too.
    expect(badgesRarityDefinition('supporting_member')->refresh()->eligible_count)->toBe(14);
});

it('is div-by-zero safe on an empty platform', function () {
    // beforeEach synced the registry and created only the role row —
    // zero users, so every definition recomputes against an empty pool.
    expect(User::query()->count())->toBe(0);

    $this->artisan('badges:rarity')->assertSuccessful();

    foreach (['debut' => 'all_users', 'chartered' => 'game_masters'] as $key => $scope) {
        $definition = badgesRarityDefinition($key)->refresh();

        expect($definition->holders_count)->toBe(0)
            ->and($definition->eligible_count)->toBe(0)
            // 0 of nobody → legendary, not a crash: without a denominator
            // there is no percentage claim to make.
            ->and($definition->rarity_class)->toBe(BadgeRarity::Legendary)
            ->and($definition->rarity_computed_at)->not->toBeNull();
    }
});

it('isolates a failing definition — healthy definitions still get their counts', function () {
    $user = User::factory()->create();
    badgesRarityAward(badgesRarityDefinition('debut'), $user);

    // Container-swapped broken recompute (the BadgesSweep failure-
    // isolation precedent): computeFor throws for 'debut' only.
    $this->app->instance(BadgesRarityService::class, new class extends BadgesRarityService
    {
        protected function computeFor(BadgeDefinition $definition): void
        {
            if ($definition->key === 'debut') {
                throw new RuntimeException('rarity recompute exploded');
            }

            parent::computeFor($definition);
        }
    });

    $this->artisan('badges:rarity')->assertFailed();

    $failed = badgesRarityDefinition('debut')->refresh();
    $healthy = badgesRarityDefinition('first_seat')->refresh();

    // The failure is reported (non-zero exit) AND isolated: the broken
    // definition keeps its previous values (null rarity until the next
    // run) while the healthy one was written regardless of sort order.
    expect($failed->rarity_class)->toBeNull()
        ->and($failed->rarity_computed_at)->toBeNull()
        ->and($healthy->holders_count)->toBe(0)
        ->and($healthy->rarity_class)->toBe(BadgeRarity::Legendary)
        ->and($healthy->rarity_computed_at)->not->toBeNull();
});

it('gates percentage display behind a minimum eligible population', function () {
    // The boundary map's display gate (D195): the holder count is always
    // shown, the "X% of eligible players" claim only above this
    // population — no rare-% claims on a tiny population.
    expect(BadgeRarity::ELIGIBLE_THRESHOLD)->toBe(100);
});

it('resolves every rarity label in both locales with distinct EN/DE wording', function () {
    foreach (['common', 'uncommon', 'rare', 'elite', 'legendary'] as $tier) {
        $key = "badges.content_badge_rarity_{$tier}";
        $en = __($key, [], 'en');
        $de = __($key, [], 'de');

        // Wording-agnostic (K004): only that each key resolves in both
        // locales and DE carries genuinely distinct copy.
        expect($en)->not->toBe($key);
        expect($en)->not->toBe('');
        expect($de)->not->toBe($key);
        expect($de)->not->toBe('');
        expect($de)->not->toBe($en);
    }
});

it('schedules the rarity recompute nightly at 06:45 behind the usual overlap guards', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $matches = collect($schedule->events())
        ->filter(fn (Event $event) => TaskIdentity::command($event) === 'badges:rarity');

    expect($matches)->toHaveCount(1);

    $event = $matches->first();

    // 06:45 runs fifteen minutes after the 06:30 sweep so counts
    // reflect that night's awards; the guards match every other
    // long-running entry in routes/console.php.
    expect($event->expression)->toBe('45 6 * * *')
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
