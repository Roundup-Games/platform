<?php

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\CreateGame;
use App\Livewire\Games\GamesPage;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\EntityCancelled;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// M084/S05-T03 — "Recreate with changes", the host-facing half of the linked
// recreate (D318/D319).
//
//   plain cancel   → the game is cancelled, no declaration on record
//   recreate       → the declaration is stamped, the SAME cancellation runs,
//                    and the host lands on the create form pre-filled from it
//
// The declaration is the host's explicit choice at cancel time; nothing else
// about the cancellation may differ from a plain cancel. Labels are resolved
// through __() — never a literal (K004).

// K014: the clock is frozen, so both the <24h boundary the offence depends on
// and the stamp on the declaration are exact.
beforeEach(function () {
    $this->travelTo(now());
});

afterEach(function () {
    $this->travelBack();
});

// ── Helpers ──────────────────────────────────────────────

/**
 * A scheduled game exactly as a late cancel needs it: it starts in twelve
 * hours and has the host plus two approved players — a roster big enough for
 * the cancellation to be recorded as an offence.
 *
 * @param  array<string, mixed>  $overrides
 */
function recreateGameTestScheduledGame(User $host, array $overrides = []): Game
{
    $game = Game::factory()->create([
        'owner_id' => $host->id,
        'campaign_id' => null,
        'name' => ['en' => 'Doors at eight'],
        'status' => GameStatus::Scheduled->value,
        'date_time' => now()->addHours(12),
        ...$overrides,
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($index = 0; $index < 2; $index++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return $game->fresh();
}

/**
 * Cancel one of the host's scheduled games through the real action and hand
 * back the cancelled row. Built by the component, never by hand, so whatever
 * the cancel path stamps is what the assertions afterwards see.
 *
 * @param  array<string, mixed>  $overrides
 */
function recreateGameTestCancelThrough(User $host, string $action, array $overrides = []): Game
{
    $game = recreateGameTestScheduledGame($host, $overrides);

    Livewire::actingAs($host)
        ->test(GamesPage::class)
        ->call($action, $game->id);

    return $game->fresh();
}

/** The offence exactly as a plain cancel records it: the report AND the host's row are LateCancel at −1.2. */
function expectRecreateGameTestOffence(Game $game, User $host): void
{
    $report = AttendanceReport::query()
        ->where('game_id', $game->id)
        ->where('reporter_id', $host->id)
        ->where('reported_id', $host->id)
        ->sole();

    expect($report->status)->toBe(AttendanceStatus::LateCancel);
    expect($report->weight_applied)->toEqualWithDelta(-1.2, 0.001);

    $hostRow = GameParticipant::query()
        ->where('game_id', $game->id)
        ->where('user_id', $host->id)
        ->sole();

    expect($hostRow->attendance_status)->toBe(AttendanceStatus::LateCancel);
    expect((float) $hostRow->attendance_weight)->toEqualWithDelta(-1.2, 0.001);
}

function recreateGameTestCancellationNotices(User $recipient): int
{
    return $recipient->notifications()->where('type', EntityCancelled::class)->count();
}

// ═══════════════════════════════════════════════════════════
// GamesPage — RECREATE WITH CHANGES
// ═══════════════════════════════════════════════════════════

describe('GamesPage — Recreate with changes', function () {
    it('declares the recreate, cancels the game and opens the create form pre-filled from it', function () {
        $host = gameTestCreateUserWithPermission();
        $game = recreateGameTestScheduledGame($host);

        Log::shouldReceive('info')
            ->once()
            ->with('recreate.declared', ['game_id' => $game->id, 'host_id' => $host->id]);
        Log::shouldReceive('info', 'debug', 'notice', 'warning', 'error')->andReturn(null);

        Livewire::actingAs($host)
            ->test(GamesPage::class)
            ->call('recreateGame', $game->id)
            ->assertRedirect(route('games.create', ['recreate' => $game->id]));

        $game->refresh();

        expect($game->status)->toBe(GameStatus::Canceled)
            ->and($game->recreate_declared_at?->format('Y-m-d H:i:s'))->toBe(now()->format('Y-m-d H:i:s'));

        expectRecreateGameTestOffence($game, $host);
    });

    it('runs the whole cancellation, whichever way the host cancels', function (string $action) {
        $host = gameTestCreateUserWithPermission();
        $game = recreateGameTestScheduledGame($host);
        $players = $game->participants()
            ->with('user')
            ->where('role', ParticipantRole::Player->value)
            ->get()
            ->map(fn (GameParticipant $participant): ?User => $participant->user);

        $waitlisted = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
        ]);

        Livewire::actingAs($host)
            ->test(GamesPage::class)
            ->call($action, $game->id);

        expect($game->fresh()->status)->toBe(GameStatus::Canceled);

        expectRecreateGameTestOffence($game, $host);

        // The roster cascade runs: nobody is left waiting for a game that is gone.
        expect($waitlisted->fresh()->status)->toBe(ParticipantStatus::Rejected);

        // Approved players are told; the host is not.
        foreach ($players as $player) {
            expect(recreateGameTestCancellationNotices($player))->toBe(1);
        }

        expect(recreateGameTestCancellationNotices($host))->toBe(0);
    })->with([
        'plain cancel' => ['cancelGame'],
        'recreate with changes' => ['recreateGame'],
    ]);

    it('refuses a game that is no longer scheduled and changes nothing', function (string $status) {
        $host = gameTestCreateUserWithPermission();
        $game = recreateGameTestScheduledGame($host, ['status' => $status]);

        Livewire::actingAs($host)
            ->test(GamesPage::class)
            ->call('recreateGame', $game->id)
            ->assertNoRedirect()
            ->assertSee(__('games.error_game_not_scheduled'));

        $game->refresh();

        expect($game->status)->toBe(GameStatus::from($status))
            ->and($game->recreate_declared_at)->toBeNull()
            ->and($game->attendanceReports()->count())->toBe(0);
    })->with([
        'already cancelled' => ['canceled'],
        'already completed' => ['completed'],
    ]);

    it('returns 403 for a user who may not update the game and changes nothing', function () {
        $host = gameTestCreateUserWithPermission();
        $other = gameTestCreateUserWithPermission();
        $game = recreateGameTestScheduledGame($host);

        Livewire::actingAs($other)
            ->test(GamesPage::class)
            ->call('recreateGame', $game->id)
            ->assertStatus(403);

        $game->refresh();

        expect($game->status)->toBe(GameStatus::Scheduled)
            ->and($game->recreate_declared_at)->toBeNull()
            ->and($game->attendanceReports()->count())->toBe(0);
    });

    it('leaves the declaration off a plain cancel', function () {
        $host = gameTestCreateUserWithPermission();
        $game = recreateGameTestCancelThrough($host, 'cancelGame');

        expect($game->status)->toBe(GameStatus::Canceled)
            ->and($game->recreate_declared_at)->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// MY GAMES BOARD — THE ACTION ON THE CARD
// ═══════════════════════════════════════════════════════════

describe('My Games board — Recreate with changes', function () {
    it('offers the action on the host’s own scheduled game, wired to that game', function () {
        $host = gameTestCreateUserWithPermission();
        $game = recreateGameTestScheduledGame($host);

        // Blade escapes the quotes inside wire:click, so the action string is asserted in
        // its escaped form. The confirm ids sit inside single-quoted x-data and are compared raw.
        actingAs($host)
            ->get('/en/dashboard/games')
            ->assertOk()
            ->assertSee(__('games.action_recreate_game'))
            ->assertSee("recreateGame('{$game->id}')")
            ->assertSee("confirmingId === 'recreate-game-{$game->id}'", escape: false)
            ->assertSee("confirmingId === 'cancel-game-{$game->id}'", escape: false)
            ->assertSee(__('games.confirm_recreate'))
            ->assertSee(__('common.action_keep'));
    });

    it('does not offer the action on a scheduled game the user only plays in', function () {
        $host = gameTestCreateUserWithPermission();
        $player = gameTestCreateUserWithPermission();
        $game = recreateGameTestScheduledGame($host);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        actingAs($player)
            ->get('/en/dashboard/games')
            ->assertOk()
            ->assertSee(__('games.action_leave_game'))
            ->assertDontSee(__('games.action_recreate_game'));
    });

    it('does not offer the action once the game is cancelled', function () {
        $host = gameTestCreateUserWithPermission();
        recreateGameTestScheduledGame($host, ['status' => GameStatus::Canceled->value]);

        actingAs($host)
            ->get('/en/dashboard/games')
            ->assertOk()
            ->assertSee(__('games.action_create_similar_session'))
            ->assertDontSee(__('games.action_recreate_game'));
    });
});

// ═══════════════════════════════════════════════════════════
// CREATE FORM — THE RECREATE NOTICE
// ═══════════════════════════════════════════════════════════

describe('CreateGame — recreate notice', function () {
    it('names the game being recreated while ?recreate= is valid', function (string $locale) {
        $host = gameTestCreateUserWithPermission();
        $old = recreateGameTestCancelThrough($host, 'recreateGame');

        app()->setLocale($locale);

        Livewire::actingAs($host)
            ->test(CreateGame::class, ['recreate' => $old->id])
            ->assertOk()
            ->assertSee(__('games.content_recreating_from', ['name' => $old->name]));
    })->with(['english' => ['en'], 'german' => ['de']]);

    it('shows no notice for a plain clone, even of a declared cancel', function () {
        $host = gameTestCreateUserWithPermission();
        $old = recreateGameTestCancelThrough($host, 'recreateGame');

        Livewire::actingAs($host)
            ->test(CreateGame::class, ['clone' => $old->id])
            ->assertOk()
            ->assertSet('step', 'form')
            ->assertSet('name', $old->name)
            ->assertDontSee(__('games.content_recreating_from', ['name' => $old->name]));
    });

    it('shows no notice for an undeclared cancel — a plain cancel is not a recreate', function () {
        $host = gameTestCreateUserWithPermission();
        $old = recreateGameTestCancelThrough($host, 'cancelGame');

        Livewire::actingAs($host)
            ->test(CreateGame::class, ['recreate' => $old->id])
            ->assertOk()
            ->assertSet('recreate', null)
            ->assertDontSee(__('games.content_recreating_from', ['name' => $old->name]));
    });

    it('never names someone else’s game, however ?recreate= arrives', function () {
        $owner = gameTestCreateUserWithPermission();
        $old = recreateGameTestCancelThrough($owner, 'recreateGame', ['visibility' => 'private']);
        $user = gameTestCreateUserWithPermission();

        Livewire::actingAs($user)
            ->test(CreateGame::class, ['recreate' => $old->id])
            ->assertOk()
            ->assertDontSee($old->name)
            ->assertDontSee(__('games.content_recreating_from', ['name' => $old->name]))
            ->set('recreate', $old->id)
            ->assertDontSee($old->name)
            ->assertDontSee(__('games.content_recreating_from', ['name' => $old->name]));
    });

    it('drops the notice once the replacement is saved', function () {
        $host = gameTestCreateUserWithPermission();
        $old = recreateGameTestCancelThrough($host, 'recreateGame');
        $notice = __('games.content_recreating_from', ['name' => $old->name]);

        // Livewire renders nothing on a redirect, so the state is what proves the
        // param is spent: the notice needs it, and the next render starts from it.
        Livewire::actingAs($host)
            ->test(CreateGame::class, ['recreate' => $old->id])
            ->assertSee($notice)
            ->set('date_time', now()->addDays(5)->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect()
            ->assertSet('recreate', null);

        expect(Game::query()->where('recreates_game_id', $old->id)->count())->toBe(1);

        // The link is spent: the same URL is now a plain form.
        Livewire::actingAs($host)
            ->test(CreateGame::class, ['recreate' => $old->id])
            ->assertOk()
            ->assertDontSee($notice);
    });
});
