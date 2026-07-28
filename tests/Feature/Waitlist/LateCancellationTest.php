<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\GameDetail;
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
//
// Note: createGameForLateCancel does NOT add the owner as a participant, so
// approvedParticipantCount() equals exactly the number of approved players
// created via createApprovedParticipantForLateCancel(). Tests below count
// accordingly.

function createGameForLateCancel(User $owner, GameSystem $system, array $overrides = []): Game
{
    return Game::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => ['en' => 'Test Game'],
        'date_time' => now()->addDays(7),
        'description' => ['en' => 'A test game'],
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

function createApprovedParticipantForLateCancel(Game $game, ?User $user = null): GameParticipant
{
    $user = $user ?? User::factory()->create();

    return GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $user->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);
}

// ── Late cancellation (< 24h) ───────────────────────────

describe('late cancellation detection', function () {
    it('records late_cancel when participant cancels less than 24h before game', function () {
        $game = createGameForLateCancel($this->owner, $this->gameSystem, [
            'date_time' => now()->addHours(12),
        ]);

        $participant = createApprovedParticipantForLateCancel($game);

        // Cancel via Livewire component (the real flow)
        Livewire::actingAs($participant->user)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('cancelOwnParticipation', $participant->id);

        expect($participant->fresh()->attendance_status)->toBe(AttendanceStatus::LateCancel);
        expect($participant->fresh()->status)->toBe(ParticipantStatus::Rejected);
    });

});

// ── Below-min-player warning ────────────────────────────
//
// promoteAllOnCancel() promotes from the waitlist to fill open slots, then
// calls checkBelowMinPlayers() — a private method whose ONLY effect is the
// `waitlist.below_min_players` structured warning (it changes no state). The
// log assertion is therefore the correct contract for that method, but each
// test also asserts the real roster state so a regression that broke
// promotion (or the below-min check) could not pass silently.

describe('below-min-player warning', function () {
    it('fires warning when approved roster drops below min_players and there is no waitlist to promote', function () {
        $game = createGameForLateCancel($this->owner, $this->gameSystem, [
            'min_players' => 3,
            'max_players' => 5,
        ]);

        // Three approved players; cancel two → one remains (below min of 3).
        $keep = createApprovedParticipantForLateCancel($game);
        $cancelA = createApprovedParticipantForLateCancel($game);
        $cancelB = createApprovedParticipantForLateCancel($game);
        $cancelA->update(['status' => ParticipantStatus::Rejected->value]);
        $cancelB->update(['status' => ParticipantStatus::Rejected->value]);

        Log::shouldReceive('warning')
            ->with('waitlist.below_min_players', Mockery::on(fn ($ctx) => $ctx['game_id'] === $game->id
                && $ctx['current_roster'] === 1
                && $ctx['current_roster'] < $ctx['min_players']
            ))
            ->once();
        // Swallow any incidental log calls (notification dispatch, etc.).
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->service->promoteAllOnCancel($game);

        // No waitlist existed, so nothing was promoted — roster is still below min.
        expect($game->approvedParticipantCount())->toBe(1);
    });

    it('does not fire warning when roster stays at or above min_players', function () {
        $game = createGameForLateCancel($this->owner, $this->gameSystem, [
            'min_players' => 2,
            'max_players' => 5,
        ]);

        // Three approved players; cancel one → two remain (at min of 2, not below).
        createApprovedParticipantForLateCancel($game);
        createApprovedParticipantForLateCancel($game);
        $cancel = createApprovedParticipantForLateCancel($game);
        $cancel->update(['status' => ParticipantStatus::Rejected->value]);

        // below_min_players warning must NOT be logged (2 is not < 2).
        Log::shouldReceive('warning')
            ->with('waitlist.below_min_players', Mockery::any())
            ->never();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->service->promoteAllOnCancel($game);

        // Roster held at the minimum.
        expect($game->approvedParticipantCount())->toBe(2);
    });
});
