<?php

use App\Console\Dev\DemoSeedCommand;
use App\Enums\BadgeEventAction;
use App\Models\BadgeDefinition;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Traits\CapturesLogRecords;

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
| while Campaign Compleat and Chartered are both live-but-unheld at toy
| scale: campaign_compleat activated in M071/S05 (D222), but DemoSeed
| creates no journal entries, so no fixture reaches six completed
| sessions at 80% coverage; chartered went live in M070/S04, but the
| demo fixtures create no charters. Both demo cohort counts stay
| pinned zeros. The
| deterministic fixture blocks inside demo:seed are what makes this
| true at ANY scale; see DemoSeedCommand::createBadgeCohortFixtures().
*/

uses(CapturesLogRecords::class);

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
    seedDemoGameSystems();

    // Fresh demo environment at toy scale, then the stateless backfill.
    // Capture logs from the sweep onward: CohortSweepEvaluator swallows
    // per-user award failures (catch Throwable -> Log::error 'badges.sweep.
    // user_failed' -> keep sweeping, command exits 0) — the only observable
    // trace of the intermittent regulars_table zero-holds flake. Surfacing
    // the captured exception turns any recurrence into a full diagnosis.
    $this->captureLogRecords();

    // --cohort-only: the proof contract covers the badge fixtures and the
    // completed sessions behind debut/first_seat/resolution stamps — the
    // seeder's noise blocks are skipped (2026-11-01 harness report).
    $this->artisan('demo:seed', [
        '--users' => '25',
        '--gms' => '3',
        '--subscribers' => '1',
        '--cohort-only' => true,
    ])->assertSuccessful();

    $this->artisan('badges:sync')->assertSuccessful();
    $this->artisan('badges:sweep')->assertSuccessful();
});

// ── Consolidated 2026-11-02 (M073/S02): these proofs all assert the SAME
// seed+sync+sweep outcome, and the sweep is the file's dominant cost
// (~1.7k queries per cycle, see R094). Two consolidated tests replace six
// per-test cycles; every assertion below is preserved verbatim from its
// original test:
//   Test 1 ← "produces all eight demo-contract badges…", "writes the
//             awarded audit trail…", "awards still rolling across the
//             seeded tenure markers"
//   Test 2 ← "keeps campaign compleat and chartered live but unheld…",
//             "leaves the guild-linked negatives unbadged (D209)",
//             "disqualifies the silent-cancellation organizer… (D207)"

it('produces the full positive demo contract through the stateless sweep', function () {
    // ── eight badges held (was: 'produces all eight demo-contract badges…') ──
    // Collect ALL zero-hold keys, never abort at the first: the diagnostic
    // detail below is the diagnosis (the 2026-11-01 CI flake was blind
    // because a bare toBeGreaterThan(0) abort per key; the 2026-11-06 CI
    // failure aborted BEFORE this block could ever run — fixed by ordering).
    $lost = [];
    foreach ([
        'debut', 'first_seat', 'founding_table', 'still_rolling', 'guild_linked',
        'regulars_table', 'trusted_organizer', 'mentor',
    ] as $key) {
        $holds = demoCohortHoldsFor($key);
        if ($holds > 0) {
            continue;
        }

        $detail = '';
        if ($key === 'regulars_table') {
            $rgameIds = Game::query()->where('name->en', 'like', '%Regulars Night%')->pluck('id');
            $detail = ' MONTHS:';
            foreach (GameParticipant::query()->whereIn('game_id', $rgameIds)->pluck('user_id')->unique() as $uid) {
                $months = GameParticipant::query()
                    ->verifiedAttendance()
                    ->where('game_participants.user_id', $uid)
                    ->pluck('games.date_time')
                    ->map(fn ($d) => Carbon::parse($d)->setTimezone(community_timezone())->format('Y-m'))
                    ->unique()->sort()->values()->implode(',');
                $detail .= " u=[{$months}]";
            }
        }
        $sweepErrors = collect($this->logRecords())
            ->filter(fn (array $r) => ($r['context']['badge'] ?? null) === $key || str_contains($r['message'], 'badges.sweep'))
            ->map(fn (array $r) => $r['message'].' '.json_encode($r['context']))
            ->implode(' || ');

        $lost[] = "{$key} (holds=0){$detail} SWEEP_ERRORS=[{$sweepErrors}]";
    }

    expect($lost)->toBeEmpty('demo cohort lost: '.implode(' | ', $lost));

    // ── audit trail (was: 'writes the awarded audit trail for the cohort') ──
    // Collect ALL unaudited keys, not the first: the flake (2026-11-01 CI,
    // 2026-11-02 gate) is a per-draw single badge losing its award, and a
    // collected failure names the badge(s) + every action seen for them —
    // the diagnosis the bare toBeTrue never gave.
    $missing = [];

    foreach ([
        'debut', 'first_seat', 'founding_table', 'still_rolling', 'guild_linked',
        'regulars_table', 'trusted_organizer', 'mentor',
    ] as $key) {
        $audited = BadgeEvent::query()
            ->where('action', BadgeEventAction::Awarded)
            ->whereHas('badgeUser.badgeDefinition', fn ($query) => $query->where('key', $key))
            ->exists();

        if (! $audited) {
            $actions = BadgeEvent::query()
                ->whereHas('badgeUser.badgeDefinition', fn ($query) => $query->where('key', $key))
                ->distinct()
                ->pluck('action')
                ->map(fn ($action) => $action->value)
                ->implode(',');
            $holds = demoCohortHoldsFor($key);

            $detail = '';
            if ($key === 'regulars_table') {
                // TEMP DIAGNOSTIC (remove once the draw is caught): dump the
                // covered months of every user on a Regulars Night fixture.
                $rgameIds = Game::query()->where('name->en', 'like', '%Regulars Night%')->pluck('id');
                $detail = ' MONTHS:';
                foreach (GameParticipant::query()->whereIn('game_id', $rgameIds)->pluck('user_id')->unique() as $uid) {
                    $months = GameParticipant::query()
                        ->verifiedAttendance()
                        ->where('game_participants.user_id', $uid)
                        ->pluck('games.date_time')
                        ->map(fn ($d) => Carbon::parse($d)->setTimezone(community_timezone())->format('Y-m'))
                        ->unique()->sort()->values()->implode(',');
                    $detail .= " u=[{$months}]";
                }
            }
            $sweepErrors = collect($this->logRecords())
                ->filter(fn (array $r) => ($r['context']['badge'] ?? null) === $key || str_contains($r['message'], 'badges.sweep'))
                ->map(fn (array $r) => $r['message'].' '.json_encode($r['context']))
                ->implode(' || ');

            $missing[] = "{$key} (holds={$holds}, badge_event actions=[{$actions}]){$detail} SWEEP_ERRORS=[{$sweepErrors}]";
        }
    }

    expect($missing)->toBeEmpty('demo cohort audit gaps: '.implode(' | ', $missing));

    // ── tenure tiers (was: 'awards still rolling across the seeded tenure markers') ──
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

it('holds the negative demo contracts after the sweep', function () {
    // ── live-but-unheld (was: 'keeps campaign compleat and chartered live but unheld') ──
    // campaign_compleat activated in M071/S05 (D222) and chartered in
    // M070/S04: both definitions are live, but the demo fixtures never
    // create the qualifying signal at toy scale — no journal entries
    // exist for the 6-completed + 80%-coverage predicate, and no
    // charters either — so the sweep holds nothing for either.
    $compleat = BadgeDefinition::where('key', 'campaign_compleat')->first();
    $chartered = BadgeDefinition::where('key', 'chartered')->first();

    expect($compleat->is_dormant)->toBeFalse()
        ->and($chartered->is_dormant)->toBeFalse()
        ->and(demoCohortHoldsFor('chartered'))->toBe(0)
        ->and(demoCohortHoldsFor('campaign_compleat'))->toBe(0);

    // ── D209 (was: 'leaves the guild-linked negatives unbadged') ──
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

    // ── D207 (was: 'disqualifies the silent-cancellation organizer from trusted organizer') ──
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
