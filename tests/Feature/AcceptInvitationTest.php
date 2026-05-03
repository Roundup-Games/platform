<?php

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use function Pest\Laravel\{actingAs, assertDatabaseHas, get};

// ── Helpers ──────────────────────────────────────────────

function acceptTestCreateGameWithOwner(array $gameAttrs = []): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        ...$gameAttrs,
    ]);

    return ['owner' => $owner, 'game' => $game];
}

function acceptTestCreateCampaignWithOwner(array $campaignAttrs = []): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        ...$campaignAttrs,
    ]);

    return ['owner' => $owner, 'campaign' => $campaign];
}

// ═══════════════════════════════════════════════════════════
// GAME: ACCEPT INVITATION
// ═══════════════════════════════════════════════════════════

describe('Game AcceptInvitation', function () {
    test('invited user can accept their invitation', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Invitation accepted');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'user_id' => $invitedUser->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    })->group('smoke');

    test('cannot accept someone else\'s invitation', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $otherUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($otherUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertSee('not yours');

        // Should remain pending
        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    })->group('smoke');

    test('cannot accept already-accepted invitation', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $user = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertSee('no longer valid');

        // Should remain unchanged
        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    })->group('smoke');

    test('cannot accept when game is full', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner(['max_players' => 2]);
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        // Fill up the game: owner + 1 approved player = 2 (max)
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);

        $filler = User::factory()->create(['profile_complete' => true]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $filler->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertSee('already full');

        // Should remain pending
        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    })->group('smoke');

    test('can accept when under capacity', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner(['max_players' => 5]);
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Invitation accepted');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    })->group('smoke');

    test('accept invitation from manage participants page', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        // Invited user shouldn't normally be on manage-participants page,
        // but the trait method should still work via any component using it
        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors();

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// GAME: DECLINE INVITATION
// ═══════════════════════════════════════════════════════════

describe('Game DeclineInvitation', function () {
    test('invited user can decline their invitation', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('declineInvitation', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('declined');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);
    })->group('smoke');

    test('cannot decline someone else\'s invitation', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $otherUser = User::factory()->create(['profile_complete' => true]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($otherUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('declineInvitation', $participant->id)
            ->assertSee('not yours');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN: ACCEPT INVITATION
// ═══════════════════════════════════════════════════════════

describe('Campaign AcceptInvitation', function () {
    test('invited user can accept their campaign invitation', function () {
        ['owner' => $owner, 'campaign' => $campaign] = acceptTestCreateCampaignWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Invitation accepted');

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'user_id' => $invitedUser->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    })->group('smoke');

    test('cannot accept someone else\'s campaign invitation', function () {
        ['owner' => $owner, 'campaign' => $campaign] = acceptTestCreateCampaignWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $otherUser = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($otherUser)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->call('acceptInvitation', $participant->id)
            ->assertSee('not yours');

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    })->group('smoke');

    test('cannot accept when campaign is full', function () {
        ['owner' => $owner, 'campaign' => $campaign] = acceptTestCreateCampaignWithOwner(['max_players' => 2]);
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);

        $filler = User::factory()->create(['profile_complete' => true]);
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $filler->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->call('acceptInvitation', $participant->id)
            ->assertSee('already full');

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN: DECLINE INVITATION
// ═══════════════════════════════════════════════════════════

describe('Campaign DeclineInvitation', function () {
    test('invited user can decline their campaign invitation', function () {
        ['owner' => $owner, 'campaign' => $campaign] = acceptTestCreateCampaignWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->call('declineInvitation', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('declined');

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// GAME DETAIL: INVITATION BANNER VISIBILITY
// ═══════════════════════════════════════════════════════════

describe('Game Invitation Banner', function () {
    test('invited user sees invitation banner on game detail', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee('Accept Invitation')
            ->assertSee('Accept');
    })->group('smoke');

    test('non-invited user does not see invitation banner', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner(['visibility' => 'public']);
        $randomUser = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($randomUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertDontSee('Accept Invitation');
    })->group('smoke');

    test('owner does not see invitation banner', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertDontSee('Accept Invitation');
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN DETAIL: INVITATION BANNER VISIBILITY
// ═══════════════════════════════════════════════════════════

describe('Campaign Invitation Banner', function () {
    test('invited user sees invitation banner on campaign detail', function () {
        ['owner' => $owner, 'campaign' => $campaign] = acceptTestCreateCampaignWithOwner();
        $invitedUser = User::factory()->create(['profile_complete' => true]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee('Accept Invitation')
            ->assertSee('Accept');
    })->group('smoke');

    test('non-invited user does not see invitation banner', function () {
        ['owner' => $owner, 'campaign' => $campaign] = acceptTestCreateCampaignWithOwner(['visibility' => 'public']);
        $randomUser = User::factory()->create(['profile_complete' => true]);

        Livewire\Livewire::actingAs($randomUser)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee('Accept Invitation');
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// FULL INVITATION LIFECYCLE (End-to-End)
// ═══════════════════════════════════════════════════════════

describe('Full Invitation Lifecycle', function () {
    test('game invite → accept lifecycle', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();

        // Invite creates participant with invited/pending
        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        // Invited user sees the banner
        $component = Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->assertSee('Accept Invitation');

        // User accepts
        $component->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Invitation accepted');

        // After accepting, banner should disappear (no more pending invitation)
        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    })->group('smoke');

    test('game invite → decline lifecycle', function () {
        ['owner' => $owner, 'game' => $game] = acceptTestCreateGameWithOwner();

        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        // User declines
        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('declineInvitation', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('declined');

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => 'rejected',
        ]);
    })->group('smoke');

    test('campaign invite → accept lifecycle', function () {
        ['owner' => $owner, 'campaign' => $campaign] = acceptTestCreateCampaignWithOwner();

        $invitedUser = User::factory()->create(['profile_complete' => true]);
        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $invitedUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($invitedUser)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->call('acceptInvitation', $participant->id)
            ->assertHasNoErrors()
            ->assertSee('Invitation accepted');

        assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    })->group('smoke');
});
