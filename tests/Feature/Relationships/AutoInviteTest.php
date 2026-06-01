<?php

use App\Enums\ParticipantRole;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Enums\ParticipantStatus;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;


// ── Helpers ─────────────────────────────────────────

function autoInviteCreateOwnerWithGamePermission(): User
{
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'create campaign', 'guard_name' => 'web']);
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'create game', 'guard_name' => 'web']);
    $owner = User::factory()->create(['profile_complete' => true]);
    setPermissionsTeamId(1);
    $owner->givePermissionTo(['create campaign', 'create game']);
    $owner->unsetRelations();
    return $owner;
}

function autoInviteCreateCampaign(array $attrs = []): array
{
    $owner = autoInviteCreateOwnerWithGamePermission();
    $campaign = Campaign::factory()->create(['owner_id' => $owner->id, ...$attrs]);
    return ['owner' => $owner, 'campaign' => $campaign];
}

// ═══════════════════════════════════════════════════════════
// AUTO-INVITE: NEW SESSION CREATES PENDING INVITATIONS
// ═══════════════════════════════════════════════════════════

describe('AutoInvite — Session Creation', function () {
    test('new session auto-invites approved campaign participants', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();
        $player1 = User::factory()->create();
        $player2 = User::factory()->create();

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player1->id, 'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value]);
        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player2->id, 'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value]);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Auto-Invite Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game)->not->toBeNull();

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id, 'user_id' => $player1->id, 'role' => ParticipantRole::Invited->value, 'status' => ParticipantStatus::Pending->value,
        ]);
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id, 'user_id' => $player2->id, 'role' => ParticipantRole::Invited->value, 'status' => ParticipantStatus::Pending->value,
        ]);
    })->group('smoke');

    test('auto-invite skips campaign owner (owner gets owner participant, not invited)', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $owner->id, 'role' => ParticipantRole::Owner->value, 'status' => ParticipantStatus::Approved->value]);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Owner Skip Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        // M048: owner gets an owner participant, not an invited one
        $ownerParticipant = GameParticipant::where('game_id', $game->id)->where('user_id', $owner->id)->first();
        expect($ownerParticipant)->not->toBeNull();
        expect($ownerParticipant->role)->toBe(\App\Enums\ParticipantRole::Owner);
        expect($ownerParticipant->status)->toBe(\App\Enums\ParticipantStatus::Approved);
        // No invited participant for the owner
        expect(GameParticipant::where('game_id', $game->id)->where('user_id', $owner->id)->where('role', 'invited')->exists())->toBeFalse();
    });

    test('mixed statuses: only approved are invited', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();
        $approvedPlayer = User::factory()->create();
        $pendingPlayer = User::factory()->create();
        $rejectedPlayer = User::factory()->create();

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $approvedPlayer->id, 'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value]);
        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $pendingPlayer->id, 'role' => ParticipantRole::Invited->value, 'status' => ParticipantStatus::Pending->value]);
        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $rejectedPlayer->id, 'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Rejected->value]);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Mixed Status Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        // M048: owner participant + 1 invited = 2 total
        expect(GameParticipant::where('game_id', $game->id)->count())->toBe(2);
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id, 'user_id' => $approvedPlayer->id, 'role' => ParticipantRole::Invited->value, 'status' => ParticipantStatus::Pending->value,
        ]);
    });

    test('campaign with no participants creates game without invites', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Empty Campaign Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game)->not->toBeNull();
        // M048: owner participant is always created
        expect(GameParticipant::where('game_id', $game->id)->count())->toBe(1);
        expect(GameParticipant::where('game_id', $game->id)->where('user_id', $owner->id)->where('role', 'owner')->exists())->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// ACCEPT AUTO-INVITATION
// ═══════════════════════════════════════════════════════════

describe('AutoInvite — Accept', function () {
    test('auto-invited user can accept invitation', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();
        $player = User::factory()->create(['profile_complete' => true]);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value]);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Accept Test Session')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save');

        $game = Game::where('campaign_id', $campaign->id)->first();
        $participant = GameParticipant::where('game_id', $game->id)->where('user_id', $player->id)->first();

        Livewire::actingAs($player)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors();

        assertDatabaseHas('game_participants', [
            'id' => $participant->id, 'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value,
        ]);
    });

    test('accept invitation respects capacity', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign(['max_players' => 2, 'bench_mode' => true]);
        $player1 = User::factory()->create(['profile_complete' => true]);
        $player2 = User::factory()->create(['profile_complete' => true]);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player1->id, 'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value]);
        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player2->id, 'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value]);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Capacity Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save');

        $game = Game::where('campaign_id', $campaign->id)->first();

        // M048: owner participant already created by ensureOwnerParticipant during session save

        // Player1 accepts — fills to 2 (max_players=2: owner + player1)
        $participant1 = GameParticipant::where('game_id', $game->id)->where('user_id', $player1->id)->first();
        Livewire::actingAs($player1)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant1->id)
            ->assertHasNoErrors();

        // Player2 tries to accept — game full, routes to bench
        $participant2 = GameParticipant::where('game_id', $game->id)->where('user_id', $player2->id)->first();
        Livewire::actingAs($player2)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant2->id)
            ->assertHasNoErrors();

        assertDatabaseHas('game_participants', [
            'id' => $participant2->id, 'user_id' => $player2->id, 'status' => ParticipantStatus::Benched->value,
        ]);
    });

    test('cannot accept another users auto-invitation', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();
        $player = User::factory()->create(['profile_complete' => true]);
        $otherUser = User::factory()->create(['profile_complete' => true]);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value]);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Wrong User Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save');

        $game = Game::where('campaign_id', $campaign->id)->first();
        // M048: must filter by player's user_id since owner participant also exists
        $participant = GameParticipant::where('game_id', $game->id)->where('user_id', $player->id)->first();

        Livewire::actingAs($otherUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertSee('not yours');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id, 'role' => ParticipantRole::Invited->value, 'status' => ParticipantStatus::Pending->value,
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// DECLINE AUTO-INVITATION
// ═══════════════════════════════════════════════════════════

describe('AutoInvite — Decline', function () {
    test('auto-invited user can decline invitation', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();
        $player = User::factory()->create(['profile_complete' => true]);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value]);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Decline Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save');

        $game = Game::where('campaign_id', $campaign->id)->first();
        $participant = GameParticipant::where('game_id', $game->id)->where('user_id', $player->id)->first();

        Livewire::actingAs($player)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('declineInvitation', $participant->id)
            ->assertHasNoErrors();

        assertDatabaseHas('game_participants', [
            'id' => $participant->id, 'status' => ParticipantStatus::Rejected->value,
        ]);
    });
});


