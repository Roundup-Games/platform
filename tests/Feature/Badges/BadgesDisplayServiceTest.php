<?php

use App\Models\BadgeDefinition;
use App\Models\BadgeUser;
use App\Models\Campaign;
use App\Models\Game;
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
// Badge-fixture serialization: see serializeBadgeDefinitionWrites() in tests/Pest.php.
beforeEach(fn () => serializeBadgeDefinitionWrites());

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

// ── Author chip (D237, spec §2.4) ───────────────────────────────────────
//
// The game-listing author chip: whitelist reading of "subscriber +
// Compleat-class only" — only AUTHOR_CHIP_KEYS may appear on a listing
// card, Compleat-class before subscriber (spec §1.5 no purchasable
// prestige), same lifecycle exclusions as the directory chip.

describe('author chip selection', function () {
    it('returns only whitelisted holds and ignores every other badge kind', function () {
        $user = User::factory()->create();

        BadgeUser::factory()->for($user)->for(displayDefinition('regulars_table', 1))->create();
        BadgeUser::factory()->for($user)->for(displayDefinition('debut', 2))->create();
        $compleat = BadgeDefinition::factory()->create([
            'key' => 'campaign_compleat',
            'name_key' => 'badges.content_badge_campaign_compleat_name',
        ]);
        BadgeUser::factory()->for($user)->for($compleat)->create();

        $chips = app(BadgesDisplayService::class)->authorChipsForUsers([$user->id]);

        expect($chips)->toHaveCount(1)
            ->and($chips[$user->id]->badge_definition_id)->toBe($compleat->id)
            ->and($chips[$user->id]->relationLoaded('badgeDefinition'))->toBeTrue();
    });

    it('prefers the Compleat-class hold over subscriber badges regardless of sort order', function () {
        $user = User::factory()->create();

        // supporting_member sorts EARLIER (1) than campaign_compleat (50),
        // so the directory lookup's lowest-sort_order rule would pick the
        // subscriber — the author chip must take whitelist precedence instead.
        $subscriber = BadgeDefinition::factory()->create([
            'key' => 'supporting_member',
            'name_key' => 'badges.content_badge_supporting_member_name',
            'sort_order' => 1,
        ]);
        $compleat = BadgeDefinition::factory()->create([
            'key' => 'campaign_compleat',
            'name_key' => 'badges.content_badge_campaign_compleat_name',
            'sort_order' => 50,
        ]);

        BadgeUser::factory()->for($user)->for($subscriber)->create();
        BadgeUser::factory()->for($user)->for($compleat)->create();

        $chips = app(BadgesDisplayService::class)->authorChipsForUsers([$user->id]);

        expect($chips[$user->id]->badge_definition_id)->toBe($compleat->id);
    });

    it('excludes revoked and lapsed holds from the author chip', function () {
        $user = User::factory()->create();

        $lapsedSubscriber = BadgeDefinition::factory()->create([
            'key' => 'supporting_member',
            'name_key' => 'badges.content_badge_supporting_member_name',
        ]);
        $revokedCompleat = BadgeDefinition::factory()->create([
            'key' => 'campaign_compleat',
            'name_key' => 'badges.content_badge_campaign_compleat_name',
        ]);

        BadgeUser::factory()->for($user)->for($lapsedSubscriber)->lapsed()->create();
        BadgeUser::factory()->for($user)->for($revokedCompleat)->revoked()->create();

        expect(app(BadgesDisplayService::class)->authorChipsForUsers([$user->id]))->toBeEmpty();
    });

    it('attaches author_chip to games in one batch, skipping non-game items and owners without holds', function () {
        $badged = User::factory()->create();
        $unbadged = User::factory()->create();

        $compleat = BadgeDefinition::factory()->create([
            'key' => 'campaign_compleat',
            'name_key' => 'badges.content_badge_campaign_compleat_name',
        ]);
        BadgeUser::factory()->for($badged)->for($compleat)->create(['tier' => 'bronze']);

        $badgedGame = Game::factory()->withoutGameSystems()->create(['owner_id' => $badged->id]);
        $unbadgedGame = Game::factory()->withoutGameSystems()->create(['owner_id' => $unbadged->id]);
        $campaign = Campaign::factory()->create(['owner_id' => $badged->id]);

        app(BadgesDisplayService::class)->attachAuthorChips([$badgedGame, $unbadgedGame, $campaign]);

        expect($badgedGame->author_chip)->toBeInstanceOf(BadgeUser::class)
            ->and($badgedGame->author_chip->tier)->toBe('bronze')
            ->and($unbadgedGame->author_chip)->toBeNull()
            // The campaign row never gained a dynamic attribute.
            ->and($campaign->getAttributes())->not->toHaveKey('author_chip');
    });

    it('returns an empty chip map without querying when no users are given', function () {
        DB::enableQueryLog();

        $chips = app(BadgesDisplayService::class)->authorChipsForUsers([]);

        DB::disableQueryLog();

        expect($chips)->toBe([])
            ->and(DB::getQueryLog())->toBeEmpty();
    });
});
