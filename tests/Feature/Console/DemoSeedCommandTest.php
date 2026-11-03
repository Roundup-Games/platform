<?php

use App\Console\Dev\DemoSeedCommand;
use App\Enums\ParticipantRole;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| DemoSeedCommand / DemoTeardownCommand
|--------------------------------------------------------------------------
|
| demo:seed is a 3,838-LOC bulk seeder (10k users, games, campaigns, social
| graph) registered ONLY outside production (AppServiceProvider::boot).
| These tests run it at toy scale to prove the invariants that break
| silently when a model or schema changes, since nothing else in CI
| executes it:
|
|  - seeding produces marker-flagged demo data across the major models,
|  - re-seeding without teardown is refused (idempotency guard),
|  - demo:teardown removes every demo row and side effect.
|
| Since M069/S05/T5 the seeder also carries the deterministic badge-cohort
| fixtures (attendance resolution stamps, old-table tenures, Discord guild
| links, closed-quarter events, first-session reviewers), so these tests
| additionally pin those surfaces and their teardown.
*/

beforeEach(function () {
    // Provision the game systems the seeder resolves by slug (its own public
    // constants are the contract). Without them the seeder fails fast with a
    // clear error — which is correct behavior, just not what these tests
    // exercise.
    seedDemoGameSystems();
});

it('seeds marker-flagged demo data across the major models', function () {
    // Full mode (no --cohort-only): the ONLY test that pays for the noise
    // blocks (social graph, scheduled sessions, campaigns, short links) —
    // keeping every insert path exercised in CI exactly once per run
    // (the policy from the 2026-11-01 harness report).
    $this->artisan('demo:seed', [
        '--users' => '25',
        '--gms' => '3',
        '--subscribers' => '1',
    ])->assertSuccessful();
    $demoUsers = User::where('email', 'like', '%@example.org')
        ->where('bio', 'like', '%[TEST]%')
        ->get();

    expect($demoUsers)->not->toBeEmpty();
    expect(Game::whereIn('owner_id', $demoUsers->pluck('id'))->count())->toBeGreaterThan(0);
    expect(Campaign::whereIn('owner_id', $demoUsers->pluck('id'))->count())->toBeGreaterThan(0);

    // Games carry a normalized location (post location-normalization steady
    // state) and an approved owner participant — two invariants most likely
    // to break when the schema evolves.
    $demoGame = Game::whereIn('owner_id', $demoUsers->pluck('id'))->first();
    expect($demoGame->location_id)->not->toBeNull();

    expect(GameParticipant::where('game_id', $demoGame->id)
        ->where('role', ParticipantRole::Owner->value)
        ->where('status', 'approved')
        ->exists())->toBeTrue();

    // Badge-cohort fixture surfaces (M069/S05/T5): completed sessions carry
    // organizer-resolved attendance at scale, and the deterministic Discord /
    // event blocks exist.
    expect(Game::whereNotNull('attendance_resolved_at')->count())->toBeGreaterThan(0);
    expect(DB::table('discord_guilds')->whereIn('owner_user_id', $demoUsers->pluck('id'))->count())->toBe(2);
    expect(DB::table('discord_guild_organizers')->whereIn('user_id', $demoUsers->pluck('id'))->count())->toBe(3);
    expect(DB::table('events')->whereIn('organizer_id', $demoUsers->pluck('id'))->count())->toBe(8);
});

it('refuses to seed twice without a teardown (idempotency guard)', function () {
    // The guard keys on marker users (@example.org + [TEST] bio) — a
    // --cohort-only seed produces them exactly like a full seed, so the
    // expensive noise blocks need not run twice for this assertion (the
    // default full invocation below still proves the guard fires for the
    // command's canonical form).
    $this->artisan('demo:seed', [
        '--users' => '25',
        '--gms' => '3',
        '--subscribers' => '1',
        '--cohort-only' => true,
    ])->assertSuccessful();

    $this->artisan('demo:seed', [
        '--users' => '25',
        '--gms' => '3',
        '--subscribers' => '1',
    ])->assertExitCode(1);
});

it('tears down every demo row and side effect', function () {
    // --cohort-only suffices here: every cascade surface asserted below
    // (games, participants, events + announcements + registrations,
    // discord guilds/organizers, badge holds) is created by the badge
    // fixtures or the completed sessions — the noise blocks add none of
    // the asserted rows. Full-scale coverage stays in the first test.
    $this->artisan('demo:seed', [
        '--users' => '25',
        '--gms' => '3',
        '--subscribers' => '1',
        '--cohort-only' => true,
    ])->assertSuccessful();

    $demoUserIds = User::where('email', 'like', '%@example.org')->pluck('id');
    $demoGameIds = Game::whereIn('owner_id', $demoUserIds)->pluck('id');
    $demoEventIds = DB::table('events')->whereIn('organizer_id', $demoUserIds)->pluck('id');

    // Badge holds ride the demo users through the sweep first, so the
    // FK-cascade claim is exercised against real badge_user/badge_events
    // rows instead of an empty table.
    $this->artisan('badges:sync')->assertSuccessful();
    $this->artisan('badges:sweep')->assertSuccessful();
    expect(BadgeUser::whereIn('user_id', $demoUserIds)->count())->toBeGreaterThan(0);

    $this->artisan('demo:teardown', ['--force' => true])->assertSuccessful();

    expect(User::whereIn('id', $demoUserIds)->count())->toBe(0);
    expect(Game::whereIn('id', $demoGameIds)->count())->toBe(0);
    expect(GameParticipant::whereIn('game_id', $demoGameIds)->count())->toBe(0);
    expect(User::where('email', 'like', '%@example.org')->count())->toBe(0);

    // Badge-cohort fixture surfaces (M069/S05/T5)
    expect(DB::table('events')->whereIn('id', $demoEventIds)->count())->toBe(0);
    expect(DB::table('event_announcements')->whereIn('event_id', $demoEventIds)->count())->toBe(0);
    expect(DB::table('event_registrations')->whereIn('event_id', $demoEventIds)->count())->toBe(0);
    expect(DB::table('discord_guilds')->whereIn('owner_user_id', $demoUserIds)->count())->toBe(0);
    expect(DB::table('discord_guild_organizers')->whereIn('user_id', $demoUserIds)->count())->toBe(0);

    // badge_user cascades on user delete; badge_events cascade with the
    // badge_user rows they audit (migration 2026_10_17_110000).
    expect(BadgeUser::whereIn('user_id', $demoUserIds)->count())->toBe(0);
    expect(BadgeEvent::whereHas('badgeUser', fn ($query) => $query->whereIn('user_id', $demoUserIds))->count())->toBe(0);
});
