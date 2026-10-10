<?php

use App\Models\Campaign;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\Review;
use App\Models\SessionZeroSurvey;
use App\Models\ShortLink;
use App\Models\Team;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * UUID identity policy (D170): RFC 9562 uuidv7 for owned primary keys via
 * HasPlatformUuid, native uuid storage, and the enforced morph map with
 * its alias data migration.
 */
describe('uuid identity policy', function () {
    it('generates uuidv7 primary keys for platform models', function () {
        $user = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $user->id]);

        // UUIDv7: 48-bit unix-ms timestamp, version nibble 7, RFC 9562 variant.
        foreach ([$user->id, $game->id] as $id) {
            expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
        }

        // v7 ids are time-ordered: the later-created game sorts after the user.
        expect(strcmp($user->id, $game->id))->toBeLessThan(0);
    });

    it('generates uuidv7 keys through the pivot models', function () {
        $user = User::factory()->create();

        $participant = $game = Game::factory()->create(['owner_id' => $user->id])
            ->participants()
            ->create(['user_id' => $user->id]);

        expect($participant->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    });

    it('keeps opaque tokens on v4 while identities are v7', function () {
        $user = User::factory()->create();
        $survey = SessionZeroSurvey::factory()->create();

        expect($survey->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
            ->and($survey->uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
            ->and($user->id)->not->toBe($survey->uuid);
    });
});

describe('enforced morph map (D170)', function () {
    it('is enforced and carries every reachable morph target', function () {
        expect(Relation::requiresMorphMap())->toBeTrue();

        $expected = [
            'campaign' => Campaign::class,
            'event' => Event::class,
            'event_announcement' => EventAnnouncement::class,
            'game' => Game::class,
            'game_participant' => GameParticipant::class,
            'game_system' => GameSystem::class,
            'location' => Location::class,
            'review' => Review::class,
            'team' => Team::class,
            'user' => User::class,
            'user_relationship' => UserRelationship::class,
        ];

        foreach ($expected as $alias => $class) {
            expect($class)->toBe(Relation::getMorphedModel($alias))
                ->and($alias)->toBe((new $class)->getMorphClass());
        }
    });

    it('writes aliases and resolves them back through relations', function () {
        $user = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $user->id]);

        $review = Review::create([
            'reviewable_type' => $game->getMorphClass(),
            'reviewable_id' => $game->id,
            'reviewer_id' => $user->id,
            'gm_profile_id' => null,
            'rating' => 5,
            'body' => 'great table',
            'status' => 'published',
        ]);

        expect($review->reviewable_type)->toBe('game')
            ->and($review->refresh()->reviewable->is($game))->toBeTrue()
            ->and(Review::whereMorphedTo('reviewable', $game)->count())->toBe(1);
    });

    it('stores native uuid columns for morph ids', function () {
        $user = User::factory()->create();

        $columns = [
            ['notifications', 'notifiable_id'],
            ['model_has_roles', 'model_id'],
        ];

        foreach ($columns as [$table, $column]) {
            $type = DB::select(
                'SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
                [$table, $column]
            )[0]->data_type;

            expect($type)->toBe('uuid');
        }

        $implicitCasts = DB::select(
            "SELECT count(*) AS n FROM pg_cast WHERE castsource = 'character varying'::regtype AND casttarget = 'uuid'::regtype AND castcontext = 'i'"
        )[0]->n;

        expect($implicitCasts)->toBe(0);
    });

    it('freezes the alias data migration in lockstep with the enforced map', function () {
        $migration = require database_path('migrations/2026_10_09_140000_migrate_morph_types_to_aliases.php');

        $frozenAliases = (new ReflectionClass($migration))->getConstant('ALIASES');

        // A map edit without a matching migration tweak silently leaves
        // stored FQCNs unconverted — the exact data split D170 cures.
        expect($frozenAliases)->toEqualCanonicalizing(Relation::morphMap());
    });

    it('converts stored FQCN morph types to aliases and back', function () {
        $user = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $user->id]);
        $link = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
        ]);

        // Legacy shape: FQCN where the enforced map expects the alias.
        expect($link->linkable_type)->toBe(Game::class);

        $migration = require database_path('migrations/2026_10_09_140000_migrate_morph_types_to_aliases.php');
        $migration->up();

        expect($link->fresh()->linkable_type)->toBe('game')
            ->and($link->fresh()->linkable)->toBeInstanceOf(Game::class);

        $migration->down();

        expect($link->fresh()->linkable_type)->toBe(Game::class);
    });
});
