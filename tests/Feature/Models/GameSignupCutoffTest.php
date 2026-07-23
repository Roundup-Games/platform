<?php

namespace Tests\Feature\Models;

use App\Models\Game;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers the signup cutoff data layer shipped in M057/S05/T01:
 * the nullable `signup_cutoff_at` column, its datetime cast, mass-assignment,
 * and the centralized `Game::signupHasClosed()` predicate (decision D124)
 * that the three participant-write paths (T02) will call.
 *
 * The gate enforcement at the write paths is covered by
 * tests/Feature/Signup/SignupCutoffGateTest.php (T02). This file isolates
 * the model-level predicate so a regression here is diagnosable without
 * spinning up a Livewire component.
 */
class GameSignupCutoffTest extends TestCase
{
    use DatabaseTransactions;

    // ── Column / cast / fillable ──────────────────────────────

    public function test_signup_cutoff_defaults_to_null_when_not_set(): void
    {
        $game = Game::factory()->create();

        $this->assertNull($game->signup_cutoff_at);
        $this->assertFalse($game->signupHasClosed(), 'A game with no cutoff must keep signups open.');
    }

    public function test_signup_cutoff_is_mass_assignable(): void
    {
        $cutoff = now()->addDays(3);

        $game = Game::factory()->create([
            'signup_cutoff_at' => $cutoff,
        ]);

        // refresh() re-reads from the DB so the datetime cast round-trips
        // through the timestamp column, exercising the cast end-to-end.
        $game->refresh();

        $this->assertInstanceOf(Carbon::class, $game->signup_cutoff_at);
        // Same-minute comparison avoids sub-second timing flakiness across
        // the PHP↔Postgres timestamp boundary.
        $this->assertEqualsWithDelta(
            $cutoff->getTimestamp(),
            $game->signup_cutoff_at->getTimestamp(),
            1,
            'The persisted cutoff should match the assigned value.',
        );
    }

    public function test_signup_cutoff_can_be_updated_after_create(): void
    {
        $game = Game::factory()->create();

        $game->update(['signup_cutoff_at' => now()->addHour()]);
        $game->refresh();

        $this->assertNotNull($game->signup_cutoff_at);
        $this->assertFalse($game->signupHasClosed());
    }

    public function test_signup_cutoff_column_exists_after_migration(): void
    {
        // The DatabaseTransactions + testcontainers bootstrap runs all
        // migrations against a fresh Postgres schema. Asserting a row can
        // be written with the column set confirms the migration applied.
        $game = Game::factory()->create(['signup_cutoff_at' => now()->subHour()]);

        $this->assertDatabaseHas('games', [
            'id' => $game->id,
            'signup_cutoff_at' => $game->signup_cutoff_at->toDateTimeString(),
        ]);
    }

    // ── signupHasClosed() predicate (decision D124) ───────────

    public function test_signup_has_not_closed_when_cutoff_is_null(): void
    {
        $game = Game::factory()->create(['signup_cutoff_at' => null]);

        $this->assertFalse($game->signupHasClosed());
    }

    public function test_signup_has_not_closed_when_cutoff_is_in_the_future(): void
    {
        $game = Game::factory()->create([
            'signup_cutoff_at' => now()->addMinutes(5),
        ]);

        $this->assertFalse($game->signupHasClosed(), 'A future cutoff must keep signups open.');
    }

    public function test_signup_has_closed_when_cutoff_is_in_the_past(): void
    {
        $game = Game::factory()->create([
            'signup_cutoff_at' => now()->subMinute(),
        ]);

        $this->assertTrue($game->signupHasClosed(), 'A past cutoff must block new signups.');
    }

    public function test_signup_has_closed_at_exact_cutoff_boundary(): void
    {
        // isPast() is inclusive of "now": the instant the cutoff arrives,
        // signups close. Using subSecond() avoids a race where the assertion
        // runs at the exact same tick as the cutoff.
        $game = Game::factory()->create([
            'signup_cutoff_at' => now()->subSecond(),
        ]);

        $this->assertTrue($game->signupHasClosed());
    }

    public function test_cutoff_set_after_start_date_still_closes_signups(): void
    {
        // Roadmap wording: "close RSVPs before OR AFTER event start". A cutoff
        // later than date_time (e.g. allow late joiners for 30 min into the
        // session) is a valid organizer choice and must still close once past.
        $game = Game::factory()->create([
            'date_time' => now()->addDays(2),
            'signup_cutoff_at' => now()->subHour(),
        ]);

        $this->assertTrue($game->signupHasClosed());
    }
}
