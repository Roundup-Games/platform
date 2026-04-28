<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(function () {
    $this->service = app(WaitlistService::class);
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Helpers ──────────────────────────────────────────────

function createGameForLateCancel(User $owner, GameSystem $system, array $overrides = []): Game
{
    return Game::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => 'Test Game',
        'date_time' => now()->addDays(7),
        'description' => 'A test game',
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => 3,
        'campaign_id' => null,
        ...$overrides,
    ]);
}

function createApprovedParticipant(Game $game, ?User $user = null): GameParticipant
{
    $user = $user ?? User::factory()->create();

    return GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $user->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);
}

// ── Late cancellation (< 24h) ───────────────────────────

describe('late cancellation detection', function () {
    it('records late_cancel when participant cancels less than 24h before game', function () {
        $game = createGameForLateCancel($this->owner, $this->gameSystem, [
            'date_time' => now()->addHours(12),
        ]);

        $participant = createApprovedParticipant($game);

        // Cancel via Livewire component (the real flow)
        Livewire::actingAs($participant->user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('cancelOwnParticipation', $participant->id);

        expect($participant->fresh()->attendance_status)->toBe(AttendanceStatus::LateCancel);
        expect($participant->fresh()->status)->toBe(ParticipantStatus::Rejected);
    });

    it('does not set late_cancel when cancellation is more than 24h before game', function () {
        $game = createGameForLateCancel($this->owner, $this->gameSystem, [
            'date_time' => now()->addDays(3),
        ]);

        $participant = createApprovedParticipant($game);

        Livewire::actingAs($participant->user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('cancelOwnParticipation', $participant->id);

        expect($participant->fresh()->status)->toBe(ParticipantStatus::Rejected);
        // attendance_status should NOT be LateCancel
        expect($participant->fresh()->attendance_status)->not->toBe(AttendanceStatus::LateCancel);
    });
});

// ── Below-min-player warning ────────────────────────────

describe('below-min-player warning', function () {
    it('fires warning when approved roster drops below min_players', function () {
        $game = createGameForLateCancel($this->owner, $this->gameSystem, [
            'min_players' => 3,
            'max_players' => 4,
        ]);

        // Add 2 more approved players (3 total including owner)
        createApprovedParticipant($game);
        $thirdPlayer = createApprovedParticipant($game);

        Log::shouldReceive('warning')
            ->with('waitlist.below_min_players', \Mockery::on(fn ($ctx) =>
                $ctx['game_id'] === $game->id
                && $ctx['current_roster'] < $ctx['min_players']
            ))
            ->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        // Cancel the third player → drops to 2 (below min of 3)
        $thirdPlayer->update(['status' => ParticipantStatus::Rejected->value]);

        $this->service->promoteAllOnCancel($game);
    });

    it('does not fire warning when roster is above min_players', function () {
        $game = createGameForLateCancel($this->owner, $this->gameSystem, [
            'min_players' => 2,
            'max_players' => 4,
        ]);

        // Add 1 more approved player (2 total including owner)
        $player = createApprovedParticipant($game);

        // Cancel the extra player → still 1 (below min of 2) — but let's test the ABOVE case
        // Create a game where cancelling still leaves above min
        $game2 = createGameForLateCancel($this->owner, $this->gameSystem, [
            'min_players' => 2,
            'max_players' => 4,
        ]);

        createApprovedParticipant($game2);
        createApprovedParticipant($game2);
        // Now: owner + 3 players = 4 approved

        // Cancel 1 player → 3 approved, still above min of 2
        $toCancel = $game2->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $game2->owner_id)
            ->first();
        $toCancel->update(['status' => ParticipantStatus::Rejected->value]);

        // below_min_players warning should NOT be logged
        Log::shouldReceive('warning')
            ->with('waitlist.below_min_players', \Mockery::any())
            ->never();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->service->promoteAllOnCancel($game2);
    });
});
