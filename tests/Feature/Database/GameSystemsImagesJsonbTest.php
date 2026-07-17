<?php

use Illuminate\Support\Facades\DB;

/**
 * Regression guard for migration 2026_07_16_100000_convert_game_systems_images_to_jsonb.
 *
 * `game_systems.images` must be `jsonb`, not `json`. PostgreSQL has no equality
 * operator for `json`, so any `DISTINCT`/`GROUP BY` over the full row (e.g.
 * Filament's default belongsToMany option query `SELECT DISTINCT game_systems.*
 * LEFT JOIN <pivot>`) throws SQLSTATE[42883] and 500s the caller.
 *
 * The user-facing admin Edit-Game 500 was fixed at the app layer in f145d8d5
 * (query overrides avoid the json column). This test pins the complementary
 * schema invariant — the latent json-equality landmine must stay removed — so
 * that a future down-migration, an inconsistent fresh provision, or a re-squash
 * that drops this migration cannot silently reintroduce `json` and resurrect the
 * failure class for any new DISTINCT/GROUP BY path.
 */
describe('game_systems.images column type', function () {
    it('is jsonb (not json) so DISTINCT/GROUP BY over the row is legal', function () {
        $column = DB::connection()->selectOne(
            'SELECT data_type, udt_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['game_systems', 'images'],
        );

        // `udt_name` is the version-stable discriminator: some PostgreSQL
        // builds report jsonb's `data_type` as 'jsonb', others as 'USER-DEFINED',
        // but `udt_name` is always 'jsonb' (and 'json' for the broken type).
        expect($column)->not->toBeNull()
            ->and($column->udt_name)->toBe('jsonb');
    });

    it('allows SELECT DISTINCT over the full row without an equality-operator error', function () {
        // The exact query shape that 500s when images is `json`. With jsonb it
        // must succeed — this is the behavioral consequence of the invariant.
        DB::connection()->selectOne(
            'SELECT DISTINCT "game_systems".* FROM "game_systems" LIMIT 1',
        );

        expect(true)->toBeTrue();
    })->group('smoke');
});
