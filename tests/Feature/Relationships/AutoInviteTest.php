<?php

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

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

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player1->id, 'role' => 'player', 'status' => 'approved']);
        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player2->id, 'role' => 'player', 'status' => 'approved']);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Auto-Invite Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game)->not->toBeNull();

        assertDatabaseHas('game_participants', [
            'game_id' => $game->id, 'user_id' => $player1->id, 'role' => 'invited', 'status' => 'pending',
        ]);
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id, 'user_id' => $player2->id, 'role' => 'invited', 'status' => 'pending',
        ]);
    });

    test('auto-invite skips campaign owner', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'approved']);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Owner Skip Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect(GameParticipant::where('game_id', $game->id)->where('user_id', $owner->id)->exists())->toBeFalse();
    });

    test('auto-invite does not invite pending participants', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();
        $pendingUser = User::factory()->create();

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $pendingUser->id, 'role' => 'invited', 'status' => 'pending']);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Pending Skip Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect(GameParticipant::where('game_id', $game->id)->where('user_id', $pendingUser->id)->exists())->toBeFalse();
    });

    test('auto-invite does not invite rejected participants', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();
        $rejectedUser = User::factory()->create();

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $rejectedUser->id, 'role' => 'player', 'status' => 'rejected']);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Rejected Skip Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect(GameParticipant::where('game_id', $game->id)->count())->toBe(0);
    });

    test('mixed statuses: only approved are invited', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();
        $approvedPlayer = User::factory()->create();
        $pendingPlayer = User::factory()->create();
        $rejectedPlayer = User::factory()->create();

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $approvedPlayer->id, 'role' => 'player', 'status' => 'approved']);
        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $pendingPlayer->id, 'role' => 'invited', 'status' => 'pending']);
        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $rejectedPlayer->id, 'role' => 'player', 'status' => 'rejected']);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Mixed Status Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect(GameParticipant::where('game_id', $game->id)->count())->toBe(1);
        assertDatabaseHas('game_participants', [
            'game_id' => $game->id, 'user_id' => $approvedPlayer->id, 'role' => 'invited', 'status' => 'pending',
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
        expect(GameParticipant::where('game_id', $game->id)->count())->toBe(0);
    });

    test('created game has correct campaign link and owner', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Link Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game)->not->toBeNull()
            ->and($game->owner_id)->toBe($owner->id)
            ->and($game->status)->toBe('scheduled');
    });
});

// ═══════════════════════════════════════════════════════════
// ACCEPT AUTO-INVITATION
// ═══════════════════════════════════════════════════════════

describe('AutoInvite — Accept', function () {
    test('auto-invited user can accept invitation', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();
        $player = User::factory()->create(['profile_complete' => true]);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

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
            'id' => $participant->id, 'role' => 'player', 'status' => 'approved',
        ]);
    });

    test('accept invitation respects capacity', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign(['max_players' => 2]);
        $player1 = User::factory()->create(['profile_complete' => true]);
        $player2 = User::factory()->create(['profile_complete' => true]);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player1->id, 'role' => 'player', 'status' => 'approved']);
        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player2->id, 'role' => 'player', 'status' => 'approved']);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Capacity Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save');

        $game = Game::where('campaign_id', $campaign->id)->first();

        // Add owner as approved to fill one slot
        GameParticipant::create(['game_id' => $game->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'approved']);

        // Player1 accepts — fills to 2 (max)
        $participant1 = GameParticipant::where('game_id', $game->id)->where('user_id', $player1->id)->first();
        Livewire::actingAs($player1)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant1->id)
            ->assertHasNoErrors();

        // Player2 tries to accept — game full
        $participant2 = GameParticipant::where('game_id', $game->id)->where('user_id', $player2->id)->first();
        Livewire::actingAs($player2)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant2->id)
            ->assertSee('already full');

        assertDatabaseHas('game_participants', [
            'id' => $participant2->id, 'role' => 'invited', 'status' => 'pending',
        ]);
    });

    test('cannot accept another users auto-invitation', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();
        $player = User::factory()->create(['profile_complete' => true]);
        $otherUser = User::factory()->create(['profile_complete' => true]);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Wrong User Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save');

        $game = Game::where('campaign_id', $campaign->id)->first();
        $participant = GameParticipant::where('game_id', $game->id)->first();

        Livewire::actingAs($otherUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertSee('not yours');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id, 'role' => 'invited', 'status' => 'pending',
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

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

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
            'id' => $participant->id, 'status' => 'rejected',
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// OBSERVABILITY: LOG STRUCTURED CONTEXT
// ═══════════════════════════════════════════════════════════

describe('AutoInvite — Observability', function () {
    test('logs structured context with auto-invite count', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();
        $player = User::factory()->create();

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

        Log::shouldReceive('info')
            ->once()
            ->with('Game session added to campaign', \Mockery::on(function ($context) use ($campaign, $owner) {
                return $context['campaign_id'] === $campaign->id
                    && $context['owner_id'] === $owner->id
                    && $context['auto_invited_count'] === 1
                    && isset($context['game_id']);
            }));
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Log Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save');
    });

    test('logs zero count when no participants', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();

        Log::shouldReceive('info')
            ->once()
            ->with('Game session added to campaign', \Mockery::on(function ($context) {
                return $context['auto_invited_count'] === 0;
            }));
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Zero Invite Log')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save');
    });
});

// ═══════════════════════════════════════════════════════════
// TRANSACTION ATOMICITY
// ═══════════════════════════════════════════════════════════

describe('AutoInvite — Transaction', function () {
    test('game creation and auto-invite are atomic', function () {
        ['owner' => $owner, 'campaign' => $campaign] = autoInviteCreateCampaign();
        $player = User::factory()->create();

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

        Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Atomic Test')
            ->set('date_time', '2026-07-01 19:00')
            ->call('save')
            ->assertRedirect();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game)->not->toBeNull();
        expect(GameParticipant::where('game_id', $game->id)->count())->toBe(1);
    });
});
