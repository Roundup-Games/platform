<?php

use App\Enums\ParticipantStatus;
use App\Enums\ParticipantRole;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\WaitlistService;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Helpers ──────────────────────────────────────────────

function createFullGameForUI(User $owner, GameSystem $system, int $maxPlayers = 3, array $overrides = []): Game
{
    $game = Game::create([
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
        'max_players' => $maxPlayers,
        'campaign_id' => null,
        ...$overrides,
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($i = 1; $i < $maxPlayers; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return $game;
}

function uiOpenSlot(Game $game): void
{
    $game->participants()
        ->where('status', ParticipantStatus::Approved->value)
        ->where('user_id', '!=', $game->owner_id)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);
}

// ── Apply when full shows waitlist position ──────────────

describe('apply when full shows waitlist position', function () {
    it('shows waitlist position after joining waitlist on full game', function () {
        $game = createFullGameForUI($this->owner, $this->gameSystem);
        $user = User::factory()->create();

        // Apply by joining waitlist
        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('joinWaitlist');

        // Reload component to see the waitlist position
        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee(__('games.content_waitlist_position', ['position' => 1]));
    });
});

// ── Promoted player sees confirm UI ─────────────────────

describe('promoted player sees confirm UI', function () {
    it('shows confirm and decline buttons after promotion', function () {
        $game = createFullGameForUI($this->owner, $this->gameSystem, maxPlayers: 2);
        $user = User::factory()->create();

        // Add to waitlist
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);

        openSlot($game);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        app(WaitlistService::class)->promoteNext($game);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee(__('games.action_confirm_spot'))
            ->assertSee(__('games.action_decline_spot'));
    });
});

// ── Host sees waitlist management ───────────────────────

describe('host sees waitlist management', function () {
    it('shows waitlist management section with manual promote button', function () {
        $game = createFullGameForUI($this->owner, $this->gameSystem);

        // Add a waitlisted user
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee(__('games.action_manual_promote'));
    });
});

// ── Confirm button promotes player ──────────────────────

describe('confirm button promotes player', function () {
    it('confirm button via Livewire confirms promoted player to approved', function () {
        $game = createFullGameForUI($this->owner, $this->gameSystem, maxPlayers: 2);
        $user = User::factory()->create();

        // Add to waitlist and promote
        $waitlisted = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);

        openSlot($game);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $promoted = app(WaitlistService::class)->promoteNext($game);
        expect($promoted->status)->toBe(ParticipantStatus::Pending);

        // User clicks confirm button
        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('confirmWaitlistSpot', $promoted->id);

        // Participant should now be approved
        expect($promoted->fresh()->status)->toBe(ParticipantStatus::Approved);
        expect($promoted->fresh()->confirmation_expires_at)->toBeNull();

        // Game should be full again
        $approvedCount = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();
        expect($approvedCount)->toBe($game->max_players);
    });
});
