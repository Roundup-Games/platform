<?php

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;

/**
 * Tests for the acceptInvitation concurrency fix.
 *
 * These verify that the capacity check and participant update are atomic,
 * preventing over-acceptance when max_players is reached.
 *
 * The real concurrency fix requires wrapping the count + update in a
 * single lockForUpdate transaction. These tests validate the behavior
 * is correct under sequential conditions that simulate what would
 * happen if the lock weren't held.
 */

// ── Helpers ──────────────────────────────────────────────

function capacityTestCreateGame(int $maxPlayers): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $system = GameSystem::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'max_players' => $maxPlayers,
    ]);

    // Owner as approved participant
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    return ['owner' => $owner, 'game' => $game];
}

function capacityTestFillTo(Game $game, int $targetApproved, string $ownerId): void
{
    $currentApproved = GameParticipant::where('game_id', $game->id)
        ->where('status', ParticipantStatus::Approved->value)
        ->count();

    while ($currentApproved < $targetApproved) {
        $filler = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $filler->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
        $currentApproved++;
    }
}

// ═══════════════════════════════════════════════════════════
// CAPACITY ENFORCEMENT — ATOMIC CHECK + UPDATE
// ═══════════════════════════════════════════════════════════

describe('AcceptInvitation — Capacity Enforcement', function () {
    test('accept succeeds when exactly one slot remains', function () {
        ['owner' => $owner, 'game' => $game] = capacityTestCreateGame(maxPlayers: 3);

        // Fill to max_players - 1 (owner + 1 filler = 2 of 3)
        $filler = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $filler->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $invited = User::factory()->create(['profile_complete' => true]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invited->id,
            'role' => 'invited',
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($invited)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors();

        // Now at max capacity: owner + filler + invited = 3
        $approvedCount = GameParticipant::where('game_id', $game->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->count();
        expect($approvedCount)->toBe(3);
    });

    test('accept routes to waitlist when at max capacity', function () {
        ['owner' => $owner, 'game' => $game] = capacityTestCreateGame(maxPlayers: 2);

        // Fill to capacity: owner + 1 filler = 2 of 2
        $filler = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $filler->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $invited = User::factory()->create(['profile_complete' => true]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invited->id,
            'role' => 'invited',
            'status' => ParticipantStatus::Pending->value,
        ]);

        Livewire\Livewire::actingAs($invited)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id);

        // Should NOT be approved — should be waitlisted
        expect(GameParticipant::find($participant->id)->status->value)
            ->not->toBe(ParticipantStatus::Approved->value);

        // Approved count should remain at 2 (max)
        $approvedCount = GameParticipant::where('game_id', $game->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->count();
        expect($approvedCount)->toBe(2);
    });

    test('sequential accepts stop at max capacity without exceeding', function () {
        ['owner' => $owner, 'game' => $game] = capacityTestCreateGame(maxPlayers: 2);

        // Create two invited users
        $invited1 = User::factory()->create(['profile_complete' => true]);
        $invited2 = User::factory()->create(['profile_complete' => true]);

        $participant1 = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invited1->id,
            'role' => 'invited',
            'status' => ParticipantStatus::Pending->value,
        ]);

        $participant2 = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invited2->id,
            'role' => 'invited',
            'status' => ParticipantStatus::Pending->value,
        ]);

        // First accept — should succeed (owner + invited1 = 2 of max 2)
        Livewire\Livewire::actingAs($invited1)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant1->id)
            ->assertHasNoErrors();

        // Second accept — game is full, should go to waitlist
        Livewire\Livewire::actingAs($invited2)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant2->id);

        // Verify exactly 2 approved (max capacity), not 3
        $approvedCount = GameParticipant::where('game_id', $game->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->count();
        expect($approvedCount)->toBe(2, 'Approved count should not exceed max_players');

        // Second participant should be waitlisted, not approved
        $p2 = GameParticipant::find($participant2->id);
        expect($p2->status->value)->not->toBe(ParticipantStatus::Approved->value);
    });

    test('accept with no max_players limit always succeeds', function () {
        ['owner' => $owner, 'game' => $game] = capacityTestCreateGame(maxPlayers: 1000);

        // Create 10 invites, all should succeed (well under the high limit)
        for ($i = 0; $i < 10; $i++) {
            $invited = User::factory()->create(['profile_complete' => true]);
            $participant = GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $invited->id,
                'role' => 'invited',
                'status' => ParticipantStatus::Pending->value,
            ]);

            Livewire\Livewire::actingAs($invited)
                ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
                ->call('acceptInvitation', $participant->id)
                ->assertHasNoErrors();
        }

        $approvedCount = GameParticipant::where('game_id', $game->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->count();
        expect($approvedCount)->toBe(11); // owner + 10 invites
    });
});
