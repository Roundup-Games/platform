<?php

use App\Models\BadgeDefinition;
use App\Models\BadgeUser;
use App\Models\User;
use App\Services\Badges\BadgesDisplayService;
use Illuminate\Support\Facades\DB;

/**
 * BadgesDisplayService (M069 S04 T4): the batched top-badge lookup
 * behind the GM Directory card chip.
 *
 * Contract pins: featured-first selection with the definition sort_order
 * tiebreak, exactly one hold per user, revoked and lapsed holds excluded
 * (lapsed stays on the full shelf, not the card), eager-loaded
 * definitions, and the empty-input short-circuit that never queries
 * (query-log guard).
 */

// ── Fixtures ──────────────────────────────────────────────────

/**
 * A definition pinned to a real badge name key, with controllable sort.
 */
function displayDefinition(string $key, int $sortOrder): BadgeDefinition
{
    return BadgeDefinition::factory()->create([
        'key' => $key,
        'name_key' => 'badges.content_badge_debut_name',
        'sort_order' => $sortOrder,
    ]);
}

// ── Selection ─────────────────────────────────────────────────

describe('top badge selection', function () {
    it('picks the featured hold first regardless of definition sort order', function () {
        $user = User::factory()->create();

        $earlier = displayDefinition('earlier_sort', 1);
        $featured = displayDefinition('featured_high_sort', 5);

        BadgeUser::factory()->for($user)->for($earlier)->create();
        BadgeUser::factory()->for($user)->for($featured)->featured()->create();

        $top = app(BadgesDisplayService::class)->topBadgesForUsers([$user->id]);

        expect($top)->toHaveCount(1)
            ->and($top[$user->id]->badge_definition_id)->toBe($featured->id);
    });

    it('falls back to the lowest definition sort order when no hold is featured', function () {
        $user = User::factory()->create();

        $later = displayDefinition('later_badge', 9);
        $earlier = displayDefinition('earlier_badge', 2);

        BadgeUser::factory()->for($user)->for($later)->create();
        BadgeUser::factory()->for($user)->for($earlier)->create();

        $top = app(BadgesDisplayService::class)->topBadgesForUsers([$user->id]);

        expect($top[$user->id]->badge_definition_id)->toBe($earlier->id);
    });

    it('returns one top badge per user keyed by user id, absent for hold-less users', function () {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $carol = User::factory()->create();

        $aliceFirst = displayDefinition('alice_first', 1);
        $aliceSecond = displayDefinition('alice_second', 2);
        $bobOnly = displayDefinition('bob_only', 1);

        BadgeUser::factory()->for($alice)->for($aliceFirst)->create();
        BadgeUser::factory()->for($alice)->for($aliceSecond)->create();
        BadgeUser::factory()->for($bob)->for($bobOnly)->create();

        $top = app(BadgesDisplayService::class)
            ->topBadgesForUsers([$alice->id, $bob->id, $carol->id]);

        expect($top)->toHaveCount(2)
            ->and($top[$alice->id]->badge_definition_id)->toBe($aliceFirst->id)
            ->and($top[$bob->id]->badge_definition_id)->toBe($bobOnly->id)
            ->not->toHaveKey($carol->id);
    });

    it('eager-loads the badge definition so card rendering adds no lazy queries', function () {
        $user = User::factory()->create();

        BadgeUser::factory()->for($user)->for(displayDefinition('eager_badge', 1))->create();

        $top = app(BadgesDisplayService::class)->topBadgesForUsers([$user->id]);

        expect($top[$user->id]->relationLoaded('badgeDefinition'))->toBeTrue();
    });
});

// ── Lifecycle exclusion ───────────────────────────────────────

describe('display-worthy filtering', function () {
    it('excludes revoked holds from selection', function () {
        $user = User::factory()->create();

        $revoked = displayDefinition('revoked_badge', 1);
        $active = displayDefinition('active_badge', 2);

        BadgeUser::factory()->for($user)->for($revoked)->revoked()->create();
        BadgeUser::factory()->for($user)->for($active)->create();

        $top = app(BadgesDisplayService::class)->topBadgesForUsers([$user->id]);

        expect($top[$user->id]->badge_definition_id)->toBe($active->id);
    });

    it('excludes lapsed holds from selection even when featured', function () {
        $user = User::factory()->create();

        $lapsed = displayDefinition('lapsed_badge', 1);
        $active = displayDefinition('active_badge', 2);

        BadgeUser::factory()->for($user)->for($lapsed)->lapsed()->featured()->create();
        BadgeUser::factory()->for($user)->for($active)->create();

        $top = app(BadgesDisplayService::class)->topBadgesForUsers([$user->id]);

        expect($top[$user->id]->badge_definition_id)->toBe($active->id);
    });

    it('returns an empty array when every hold is revoked or lapsed', function () {
        $user = User::factory()->create();

        BadgeUser::factory()->for($user)->for(displayDefinition('revoked_only', 1))->revoked()->create();
        BadgeUser::factory()->for($user)->for(displayDefinition('lapsed_only', 2))->lapsed()->create();

        expect(app(BadgesDisplayService::class)->topBadgesForUsers([$user->id]))->toBeEmpty();
    });
});

// ── Batching short-circuit ────────────────────────────────────

describe('empty input', function () {
    it('returns an empty array without querying the database', function () {
        DB::enableQueryLog();

        $top = app(BadgesDisplayService::class)->topBadgesForUsers([]);

        DB::disableQueryLog();

        expect($top)->toBe([])
            ->and(DB::getQueryLog())->toBeEmpty();
    });
});
