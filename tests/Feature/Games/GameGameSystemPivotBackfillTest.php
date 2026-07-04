<?php

use App\Enums\GameType;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use Illuminate\Support\Facades\DB;

/**
 * S06/T01 backfill contract for the game_game_system / campaign_game_system
 * pivot tables.
 *
 * The pivot tables and their backfill INSERTs run once at Testcontainers
 * bootstrap (tests/bootstrap.php) against an empty schema, so by the time a
 * feature test runs they have already executed against zero rows. This test
 * therefore re-runs the exact backfill SQL statements (each guarded by
 * ON CONFLICT DO NOTHING, so re-execution is idempotent and faithful) against
 * a populated dataset to prove the backfill produces exactly one pivot row
 * per offered system and that the formula documented in S06-PLAN holds.
 *
 * Each test starts inside a DatabaseTransactions wrapper, so the games and
 * game_systems tables are empty at the start of every case — the assertions
 * are computed live against the rows each test creates.
 */

// The two idempotent backfill statements copied verbatim from
// 2026_07_29_100000 (game_game_system) and 2026_07_29_110000 (campaign_game_system).
function backfillGamePivot(): void
{
    DB::statement(<<<'SQL'
        INSERT INTO game_game_system (game_id, game_system_id)
        SELECT id, game_system_id FROM games WHERE game_system_id IS NOT NULL
        ON CONFLICT DO NOTHING
    SQL);
    DB::statement(<<<'SQL'
        INSERT INTO game_game_system (game_id, game_system_id)
        SELECT g.id, sys::uuid FROM games g, jsonb_array_elements_text(g.game_systems) AS sys
        WHERE g.game_systems IS NOT NULL ON CONFLICT DO NOTHING
    SQL);
}

function backfillCampaignPivot(): void
{
    DB::statement(<<<'SQL'
        INSERT INTO campaign_game_system (campaign_id, game_system_id)
        SELECT id, game_system_id FROM campaigns WHERE game_system_id IS NOT NULL
        ON CONFLICT DO NOTHING
    SQL);
}

describe('game_game_system pivot backfill', function () {
    it('satisfies the S06 formula: COUNT == non-null anchors + (array_len - 1)', function () {
        // 1 single-system board_game (anchor set, array null) and 1 multi-system
        // Gathering offering 3 systems (anchor = array[0]).
        $single = Game::factory()->create();
        $systems = GameSystem::factory()->count(3)->create();
        [$a, $b, $c] = $systems->modelKeys();
        Game::factory()->gathering()->withGameSystems([$a, $b, $c])->create();

        backfillGamePivot();

        $expected = (int) DB::table('games')->whereNotNull('game_system_id')->count()
            + (int) DB::select(<<<'SQL'
                SELECT COALESCE(SUM(jsonb_array_length(game_systems) - 1), 0) AS agg
                FROM games WHERE game_systems IS NOT NULL
            SQL)[0]->agg;

        $actual = (int) DB::table('game_game_system')->count();

        expect($actual)->toBe($expected)        // 1 + 3 = 4
            ->and($expected)->toBe(4);
    });

    it('dedupes the cached anchor so a Gathering gets exactly one row per offered system', function () {
        $systems = GameSystem::factory()->count(3)->create();
        [$a, $b, $c] = $systems->modelKeys();
        $gathering = Game::factory()->gathering()->withGameSystems([$a, $b, $c])->create();

        backfillGamePivot();

        $rows = DB::table('game_game_system')
            ->where('game_id', $gathering->id)
            ->orderBy('game_system_id')
            ->pluck('game_system_id')
            ->all();

        $expected = [$a, $b, $c];
        sort($expected);
        expect($rows)->toBe($expected)          // exactly {A,B,C}, anchor A not duplicated
            ->and(count($rows))->toBe(3);
    });

    it('gives every multi-system Gathering at least one pivot row', function () {
        Game::factory()->gathering()->withGameSystems(
            GameSystem::factory()->count(2)->create()->modelKeys()
        )->create();
        Game::factory()->gathering()->withGameSystems(
            GameSystem::factory()->count(1)->create()->modelKeys()
        )->create();

        backfillGamePivot();

        // Every game whose game_systems array is non-null must have >= 1 row.
        $gatherings = DB::table('games')->whereNotNull('game_systems')->pluck('id');
        foreach ($gatherings as $id) {
            expect(DB::table('game_game_system')->where('game_id', $id)->exists())->toBeTrue();
        }
    });

    it('leaves the legacy columns untouched (additive-only)', function () {
        $single = Game::factory()->create(['game_type' => GameType::BoardGame->value]);
        $originalAnchor = $single->fresh()->game_system_id;

        backfillGamePivot();

        // The backfill never writes to games.game_system_id / game_systems.
        expect($single->fresh()->game_system_id)->toBe($originalAnchor);
    });
});

describe('campaign_game_system pivot backfill', function () {
    it('backfills one row per non-null campaign anchor', function () {
        $withSystem = Campaign::factory()->create();
        // A campaign with a null anchor contributes no pivot row.
        Campaign::factory()->create(['game_system_id' => null]);

        backfillCampaignPivot();

        $expected = (int) DB::table('campaigns')->whereNotNull('game_system_id')->count();
        $actual = (int) DB::table('campaign_game_system')->count();

        expect($actual)->toBe($expected)         // 1
            ->and($actual)->toBe(1);
    });
});

describe('referential integrity (enforced by FK constraints)', function () {
    it('has no orphaned game_system_id rows and cascades on game deletion', function () {
        $systems = GameSystem::factory()->count(2)->create();
        [$a, $b] = $systems->modelKeys();
        $gathering = Game::factory()->gathering()->withGameSystems([$a, $b])->create();
        backfillGamePivot();

        // No orphans pre-delete.
        $orphans = DB::select(<<<'SQL'
            SELECT COUNT(*) AS agg FROM game_game_system p
            WHERE NOT EXISTS (SELECT 1 FROM games g WHERE g.id = p.game_id)
               OR NOT EXISTS (SELECT 1 FROM game_systems s WHERE s.id = p.game_system_id)
        SQL)[0]->agg;
        expect((int) $orphans)->toBe(0);

        // cascadeOnDelete: deleting the game removes its pivot rows.
        $gathering->forceDelete();
        expect(DB::table('game_game_system')->where('game_id', $gathering->id)->exists())->toBeFalse();
    });

    it('is idempotent: re-running the backfill changes nothing', function () {
        $systems = GameSystem::factory()->count(3)->create();
        [$a, $b, $c] = $systems->modelKeys();
        Game::factory()->gathering()->withGameSystems([$a, $b, $c])->create();

        backfillGamePivot();
        $firstRun = (int) DB::table('game_game_system')->count();

        backfillGamePivot();
        $secondRun = (int) DB::table('game_game_system')->count();

        expect($secondRun)->toBe($firstRun);
    });
});
