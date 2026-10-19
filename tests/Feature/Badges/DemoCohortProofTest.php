<?php

use App\Console\Dev\DemoSeedCommand;
use App\Enums\BadgeEventAction;
use App\Models\BadgeDefinition;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Demo Cohort Proof (M069/S05/T5)
|--------------------------------------------------------------------------
|
| The S05 roadmap demo statement, executed literally: on a fresh demo
| environment (demo:seed at toy scale), the stateless backfill —
| badges:sync + badges:sweep, no per-badge wiring — produces at least
| one hold for EACH of the eight demo-contract badges:
|
|   debut, first_seat, founding_table, still_rolling, guild_linked,
|   regulars_table, trusted_organizer, mentor
|
| while Campaign Compleat stays registered but dormant (D187/D189:
| NoOpEvaluator + is_dormant — zero holds ever). Chartered went live
| in M070/S04, but the demo fixtures create no charters, so its demo
| cohort count stays a pinned zero. The
| deterministic fixture blocks inside demo:seed are what makes this
| true at ANY scale; see DemoSeedCommand::createBadgeCohortFixtures().
*/

/**
 * Holds (badge_user rows) for one registry key.
 */
function demoCohortHoldsFor(string $key): int
{
    return BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', $key))
        ->count();
}

/**
 * Whether the given user holds the given badge.
 */
function demoCohortUserHolds(string $userId, string $key): bool
{
    return BadgeUser::query()
        ->where('user_id', $userId)
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', $key))
        ->exists();
}

beforeEach(function () {
    // Same provisioning contract as DemoSeedCommandTest: the seeder resolves
    // its game systems by slug from its own public constants.
    foreach (DemoSeedCommand::BOARD_GAME_SLUGS as $slug => $meta) {
        GameSystem::firstOrCreate(
            ['slug' => $slug],
            ['type' => 'boardgame', 'name' => ['en' => ucfirst((string) $slug)], 'min_players' => $meta['min'], 'max_players' => $meta['max'], 'average_play_time' => $meta['dur']],
        );
    }
    foreach (DemoSeedCommand::TTRPG_SLUGS as $slug => $meta) {
        GameSystem::firstOrCreate(
            ['slug' => $slug],
            ['type' => 'ttrpg', 'name' => ['en' => ucfirst((string) $slug)], 'min_players' => $meta['min'], 'max_players' => $meta['max']],
        );
    }

    // Fresh demo environment at toy scale, then the stateless backfill.
    $this->artisan('demo:seed', [
        '--users' => '25',
        '--gms' => '3',
        '--subscribers' => '1',
    ])->assertSuccessful();

    $this->artisan('badges:sync')->assertSuccessful();
    $this->artisan('badges:sweep')->assertSuccessful();
});

it('produces all eight demo-contract badges through the stateless sweep backfill', function () {
    foreach ([
        'debut', 'first_seat', 'founding_table', 'still_rolling', 'guild_linked',
        'regulars_table', 'trusted_organizer', 'mentor',
    ] as $key) {
        expect(demoCohortHoldsFor($key))->toBeGreaterThan(0);
    }
});

it('writes the awarded audit trail for the cohort', function () {
    foreach ([
        'debut', 'first_seat', 'founding_table', 'still_rolling', 'guild_linked',
        'regulars_table', 'trusted_organizer', 'mentor',
    ] as $key) {
        $audited = BadgeEvent::query()
            ->where('action', BadgeEventAction::Awarded)
            ->whereHas('badgeUser.badgeDefinition', fn ($query) => $query->where('key', $key))
            ->exists();

        expect($audited)->toBeTrue();
    }
});

it('keeps campaign compleat dormant while chartered is live but unheld in the demo cohort', function () {
    // D187/D189: campaign_compleat stays dormant (signal ships M071/S05);
    // chartered activated in M070/S04 — live definition, but the demo
    // fixtures create no charters, so the fully-signed signal never
    // exists at toy scale and the sweep holds nothing.
    $compleat = BadgeDefinition::where('key', 'campaign_compleat')->first();
    $chartered = BadgeDefinition::where('key', 'chartered')->first();

    expect($compleat->is_dormant)->toBeTrue()
        ->and($chartered->is_dormant)->toBeFalse()
        ->and(demoCohortHoldsFor('chartered'))->toBe(0)
        ->and(demoCohortHoldsFor('campaign_compleat'))->toBe(0);
});

it('leaves the guild-linked negatives unbadged (D209)', function () {
    // Too-young opt-ins: present in the fixtures, never awarded.
    $youngOrganizerIds = DB::table('discord_guild_organizers')
        ->whereNotNull('opted_in_at')
        ->where('opted_in_at', '>', now()->subDays(30))
        ->pluck('user_id');

    expect($youngOrganizerIds)->not->toBeEmpty();

    foreach ($youngOrganizerIds as $organizerId) {
        expect(demoCohortUserHolds((string) $organizerId, 'guild_linked'))->toBeFalse();
    }

    // Aged opt-ins on paused guilds: connectedness proxy fails.
    $pausedOrganizerIds = DB::table('discord_guild_organizers as o')
        ->join('discord_guilds as g', 'g.id', '=', 'o.guild_id')
        ->where('g.paused', true)
        ->whereNotNull('o.opted_in_at')
        ->pluck('o.user_id');

    expect($pausedOrganizerIds)->not->toBeEmpty();

    foreach ($pausedOrganizerIds as $organizerId) {
        expect(demoCohortUserHolds((string) $organizerId, 'guild_linked'))->toBeFalse();
    }
});

it('disqualifies the silent-cancellation organizer from trusted organizer (D207)', function () {
    // Organizers with a cancelled event carrying NO announcement — the
    // fixture's negative case: 3 completed events and an avg-4.5 quarter,
    // sunk by a 25% unannounced-cancellation rate.
    $silentOrganizerIds = DB::table('events as e')
        ->where('e.status', 'cancelled')
        ->whereNotExists(function ($query): void {
            $query->select(DB::raw(1))
                ->from('event_announcements as a')
                ->whereColumn('a.event_id', 'e.id');
        })
        ->pluck('e.organizer_id');

    expect($silentOrganizerIds)->not->toBeEmpty();

    foreach ($silentOrganizerIds as $organizerId) {
        expect(demoCohortUserHolds((string) $organizerId, 'trusted_organizer'))->toBeFalse();
    }
});

it('awards still rolling across the seeded tenure markers', function () {
    // The three marker GMs host tables ~1950/~1200/~400 days back — the
    // earliest table each of them owns — so each crosses at least year_1.
    $holders = BadgeUser::query()
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'still_rolling'))
        ->whereNotNull('tier')
        ->get();

    expect($holders)->not->toBeEmpty()
        ->and($holders->pluck('tier')->unique()->intersect(['year_1', 'year_3', 'year_5']))->not->toBeEmpty();

    // Every still-rolling holder is a demo user with an owned past table.
    foreach ($holders as $hold) {
        expect(User::whereKey($hold->user_id)->exists())->toBeTrue();
    }
});
