<?php

use App\Models\Event;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

//
// R089 — the storage flip migration: existing wall-Berlin-labeled rows are
// reinterpreted as Europe/Berlin wall time → true UTC instants (DST-correct
// per row), users.timezone_manually_set lands in the same migration, and a
// second execution is a no-op (the flag column doubles as the applied
// marker — pre-launch one-way door, down() drops only the column).
//

function runTrueUtcMigration(): void
{
    $migration = require database_path('migrations/2026_10_31_090000_true_utc_instants_and_timezone_override.php');
    $migration->up();
}

it('shifts wall-clock rows to true UTC instants and adds the override flag', function () {
    $game = Game::factory()->create(['expected_duration' => 0]);
    DB::table('games')->where('id', $game->id)->update([
        // Berlin wall times stored under the UTC label (the old convention).
        'date_time' => '2026-07-01 19:00:00',        // CEST (+2) in effect
        'signup_cutoff_at' => '2026-01-15 20:00:00', // CET (+1) in effect
    ]);

    $event = Event::factory()->create();
    DB::table('events')->where('id', $event->id)->update([
        'registration_opens_at' => '2026-01-15 09:00:00', // CET (+1)
    ]);

    // Simulate the pre-migration state: flag column absent.
    Schema::table('users', fn ($t) => $t->dropColumn('timezone_manually_set'));

    runTrueUtcMigration();

    expect(Schema::hasColumn('users', 'timezone_manually_set'))->toBeTrue()
        ->and(DB::table('games')->where('id', $game->id)->value('date_time'))->toBe('2026-07-01 17:00:00')
        ->and(DB::table('games')->where('id', $game->id)->value('signup_cutoff_at'))->toBe('2026-01-15 19:00:00')
        ->and(DB::table('events')->where('id', $event->id)->value('registration_opens_at'))->toBe('2026-01-15 08:00:00')
        // Default: device capture is allowed until the user pins a zone.
        ->and(User::first()->timezone_manually_set)->toBeFalse();
});

it('is idempotent — a second execution shifts nothing', function () {
    $game = Game::factory()->create(['expected_duration' => 0]);
    DB::table('games')->where('id', $game->id)->update(['date_time' => '2026-07-01 19:00:00']);

    Schema::table('users', fn ($t) => $t->dropColumn('timezone_manually_set'));

    runTrueUtcMigration();
    $firstRun = DB::table('games')->where('id', $game->id)->value('date_time');
    expect($firstRun)->toBe('2026-07-01 17:00:00');

    // Re-run: the timezone_manually_set guard returns before touching data.
    runTrueUtcMigration();

    expect(DB::table('games')->where('id', $game->id)->value('date_time'))->toBe($firstRun);
});

it('leaves null columns and date-only event columns untouched', function () {
    $game = Game::factory()->create(['expected_duration' => 0]);
    DB::table('games')->where('id', $game->id)->update([
        'date_time' => '2026-07-01 19:00:00',
        'signup_cutoff_at' => null,
    ]);

    $event = Event::factory()->create([
        'start_date' => '2026-03-05',
        'end_date' => '2026-03-07',
    ]);

    Schema::table('users', fn ($t) => $t->dropColumn('timezone_manually_set'));
    runTrueUtcMigration();

    expect(DB::table('games')->where('id', $game->id)->value('signup_cutoff_at'))->toBeNull()
        // DATE columns carry no wall-time instant — deliberately unshifted.
        ->and(DB::table('events')->where('id', $event->id)->value('start_date'))->toBe('2026-03-05')
        ->and(DB::table('events')->where('id', $event->id)->value('end_date'))->toBe('2026-03-07');
});
