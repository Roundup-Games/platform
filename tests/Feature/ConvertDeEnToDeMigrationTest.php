<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ═══════════════════════════════════════════════════════════
// DE+EN → DE CONVERSION MIGRATION
// ═══════════════════════════════════════════════════════════

/**
 * Instantiate and run the migration directly for test isolation.
 */
function runConvertDeEnMigration(): void
{
    $migration = require database_path('migrations/2026_04_23_231358_convert_de_en_to_de_on_all_tables.php');
    $migration->up();
}

describe('ConvertDeEnToDe migration', function () {
    it('converts de+en to de on games.language', function () {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Game Test User ' . uniqid(),
            'email' => 'game-test-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $id = (string) Str::uuid();
        DB::table('games')->insert([
            'id' => $id,
            'language' => 'de+en',
            'name' => 'Test Game DeEn',
            'owner_id' => $userId,
            'date_time' => now()->addDay(),
            'description' => 'test',
            'expected_duration' => 120,
            'location' => json_encode(['city' => 'Berlin']),
        ]);

        runConvertDeEnMigration();

        expect(DB::table('games')->where('id', $id)->value('language'))->toBe('de');

        DB::table('games')->where('id', $id)->delete();
        DB::table('users')->where('id', $userId)->delete();
    });

    it('converts de+en to de on campaigns.language', function () {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Campaign Test User ' . uniqid(),
            'email' => 'campaign-test-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $id = (string) Str::uuid();
        DB::table('campaigns')->insert([
            'id' => $id,
            'language' => 'de+en',
            'name' => 'Test Campaign DeEn',
            'owner_id' => $userId,
            'description' => 'test',
            'recurrence' => 'weekly',
            'time_of_day' => '18:00',
            'session_duration' => 120,
        ]);

        runConvertDeEnMigration();

        expect(DB::table('campaigns')->where('id', $id)->value('language'))->toBe('de');

        DB::table('campaigns')->where('id', $id)->delete();
        DB::table('users')->where('id', $userId)->delete();
    });

    it('converts de+en to de on events.content_language', function () {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Event Test User ' . uniqid(),
            'email' => 'event-test-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $id = (string) Str::uuid();
        DB::table('events')->insert([
            'id' => $id,
            'content_language' => 'de+en',
            'name' => 'Test Event DeEn',
            'slug' => 'test-event-deen-' . uniqid(),
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'organizer_id' => $userId,
        ]);

        runConvertDeEnMigration();

        expect(DB::table('events')->where('id', $id)->value('content_language'))->toBe('de');

        DB::table('events')->where('id', $id)->delete();
        DB::table('users')->where('id', $userId)->delete();
    });

    it('converts de+en to de on users.preferred_language', function () {
        $id = DB::table('users')->insertGetId([
            'preferred_language' => 'de+en',
            'name' => 'Test User DeEn ' . uniqid(),
            'email' => 'test-deen-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        runConvertDeEnMigration();

        expect(DB::table('users')->where('id', $id)->value('preferred_language'))->toBe('de');

        DB::table('users')->where('id', $id)->delete();
    });

    it('does not alter rows that already have de or en', function () {
        $userId = DB::table('users')->insertGetId([
            'name' => 'En Test User ' . uniqid(),
            'email' => 'en-test-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $gameId = (string) Str::uuid();
        DB::table('games')->insert([
            'id' => $gameId,
            'language' => 'en',
            'name' => 'Test Game En',
            'owner_id' => $userId,
            'date_time' => now()->addDay(),
            'description' => 'test',
            'expected_duration' => 120,
            'location' => json_encode(['city' => 'Berlin']),
        ]);

        runConvertDeEnMigration();

        expect(DB::table('games')->where('id', $gameId)->value('language'))->toBe('en');

        DB::table('games')->where('id', $gameId)->delete();
        DB::table('users')->where('id', $userId)->delete();
    });

    it('is idempotent — running twice has no extra effect', function () {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Idempotent Test User ' . uniqid(),
            'email' => 'idempotent-test-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);

        $id = (string) Str::uuid();
        DB::table('games')->insert([
            'id' => $id,
            'language' => 'de+en',
            'name' => 'Test Idempotent',
            'owner_id' => $userId,
            'date_time' => now()->addDay(),
            'description' => 'test',
            'expected_duration' => 120,
            'location' => json_encode(['city' => 'Berlin']),
        ]);

        runConvertDeEnMigration();
        runConvertDeEnMigration();

        expect(DB::table('games')->where('id', $id)->value('language'))->toBe('de');
        expect(DB::table('games')->where('language', 'de+en')->count())->toBe(0);

        DB::table('games')->where('id', $id)->delete();
        DB::table('users')->where('id', $userId)->delete();
    });
});
