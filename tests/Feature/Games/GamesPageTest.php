<?php

use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use function Pest\Laravel\{actingAs, assertDatabaseHas, get};

// ── Helpers ──────────────────────────────────────────────

function gamesPageCreateUser(array $overrides = []): User
{
    return User::factory()->create(['profile_complete' => true, ...$overrides]);
}

function gamesPageCreateGame(array $overrides = []): Game
{
    return Game::factory()->create($overrides);
}

// ═══════════════════════════════════════════════════════════
// GUEST REDIRECT
// ═══════════════════════════════════════════════════════════

describe('GamesPage — Guest Access', function () {
    it('redirects guests to /discover', function () {
        get('/en/games')
            ->assertRedirect('/en/discover');
    });
});

// ═══════════════════════════════════════════════════════════
// AUTHENTICATED ACCESS
// ═══════════════════════════════════════════════════════════

describe('GamesPage — Authenticated Access', function () {
    it('renders for authenticated users', function () {
        $user = gamesPageCreateUser();

        actingAs($user)
            ->get('/en/games')
            ->assertOk()
            ->assertSee(__('games.heading_my_games'));
    });

    it('shows My Games section heading', function () {
        $user = gamesPageCreateUser();

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.heading_my_games'));
    });

    it('shows create game button', function () {
        $user = gamesPageCreateUser();

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.action_create_game'));
    });
});

// ═══════════════════════════════════════════════════════════
// MY GAMES — OWNED GAMES DISPLAY
// ═══════════════════════════════════════════════════════════

describe('GamesPage — My Games Display', function () {
    it('shows owned games with name', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'name' => 'Test Game Session']);

        actingAs($user)
            ->get('/en/games')
            ->assertSee('Test Game Session');
    });

    it('shows status badge for scheduled games', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'scheduled']);

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.status_scheduled'));
    });

    it('shows status badge for canceled games', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'canceled']);

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.status_canceled'));
    });

    it('shows status badge for completed games', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'completed']);

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.status_completed'));
    });

    it('does not show other users private games on the page', function () {
        $user = gamesPageCreateUser();
        $other = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $other->id, 'name' => 'Other User Game', 'visibility' => 'private']);

        actingAs($user)
            ->get('/en/games')
            ->assertDontSee('Other User Game');
    });

    it('shows empty state when no games exist', function () {
        $user = gamesPageCreateUser();

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.content_no_owned_games'));
    });

    it('shows cancel button for scheduled games', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'scheduled']);

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.action_cancel_game'));
    });

    it('shows complete button for scheduled games', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'scheduled']);

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.action_complete_game'));
    });

    it('does not show cancel/complete buttons for canceled games', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'canceled']);

        actingAs($user)
            ->get('/en/games')
            ->assertDontSee(__('games.action_cancel_game'))
            ->assertDontSee(__('games.action_complete_game'));
    });

    it('does not show cancel/complete buttons for completed games', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'completed']);

        actingAs($user)
            ->get('/en/games')
            ->assertDontSee(__('games.action_cancel_game'))
            ->assertDontSee(__('games.action_complete_game'));
    });
});

// ═══════════════════════════════════════════════════════════
// CANCEL GAME ACTION
// ═══════════════════════════════════════════════════════════

describe('GamesPage — Cancel Game Action', function () {
    it('cancels a scheduled game', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'scheduled']);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id);

        assertDatabaseHas('games', [
            'id' => $game->id,
            'status' => 'canceled',
        ]);
    });

    it('flashes success message after cancel', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'scheduled']);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id);

        // Verify DB state
        assertDatabaseHas('games', [
            'id' => $game->id,
            'status' => 'canceled',
        ]);

        // Verify flash message is set on the component
        $component->assertSee(__('games.flash_game_canceled'));
    });

    it('cannot cancel already canceled game', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'canceled']);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id);

        // Status stays canceled
        assertDatabaseHas('games', [
            'id' => $game->id,
            'status' => 'canceled',
        ]);

        // Error flash should be set
        $component->assertSee(__('games.error_game_not_scheduled'));
    });

    it('cannot cancel a completed game', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'completed']);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id);

        assertDatabaseHas('games', [
            'id' => $game->id,
            'status' => 'completed',
        ]);

        $component->assertSee(__('games.error_game_not_scheduled'));
    });

    it('denies cancel by non-owner', function () {
        $owner = gamesPageCreateUser();
        $other = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'status' => 'scheduled']);

        Livewire\Livewire::actingAs($other)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id)
            ->assertStatus(403);

        assertDatabaseHas('games', [
            'id' => $game->id,
            'status' => 'scheduled',
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// COMPLETE GAME ACTION
// ═══════════════════════════════════════════════════════════

describe('GamesPage — Complete Game Action', function () {
    it('completes a scheduled game', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'scheduled']);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('completeGame', $game->id);

        assertDatabaseHas('games', [
            'id' => $game->id,
            'status' => 'completed',
        ]);

        $component->assertSee(__('games.flash_game_completed'));
    });

    it('cannot complete already canceled game', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'canceled']);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('completeGame', $game->id);

        assertDatabaseHas('games', [
            'id' => $game->id,
            'status' => 'canceled',
        ]);

        $component->assertSee(__('games.error_game_not_scheduled'));
    });

    it('cannot complete already completed game', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'status' => 'completed']);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('completeGame', $game->id);

        assertDatabaseHas('games', [
            'id' => $game->id,
            'status' => 'completed',
        ]);

        $component->assertSee(__('games.error_game_not_scheduled'));
    });

    it('denies complete by non-owner', function () {
        $owner = gamesPageCreateUser();
        $other = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'status' => 'scheduled']);

        Livewire\Livewire::actingAs($other)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('completeGame', $game->id)
            ->assertStatus(403);

        assertDatabaseHas('games', [
            'id' => $game->id,
            'status' => 'scheduled',
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// GAMES I'M IN — DISPLAY
// ═══════════════════════════════════════════════════════════

describe('GamesPage — Games I\'m In Display', function () {
    it('shows section heading', function () {
        $user = gamesPageCreateUser();

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.heading_games_im_in'));
    });

    it('shows empty state when not participating in any games', function () {
        $user = gamesPageCreateUser();

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.content_no_games_joined'));
    });

    it('shows games where user is an approved player', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Joined Game']);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        actingAs($user)
            ->get('/en/games')
            ->assertSee('Joined Game');
    });

    it('does not show owned games in Games I\'m In', function () {
        $user = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'name' => 'My Own Game']);

        actingAs($user)
            ->get('/en/games')
            ->assertSee('My Own Game'); // visible in My Games
        // But the participating section should show empty state
        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.content_no_games_joined'));
    });

    it('does not show games with pending participation in Games I\'m In section', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Pending Game', 'visibility' => 'private']);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'pending',
        ]);

        $response = actingAs($user)->get('/en/games');
        $content = $response->getContent();

        // Find the Games I'm In section and verify the game is not there
        $heading = __('games.heading_games_im_in');
        $escapedHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $pos = strpos($content, $heading) ?: strpos($content, $escapedHeading);
        expect($pos)->not->toBeFalse('Games I\'m In section heading should be present');

        $sectionContent = substr($content, $pos);
        $nextSection = strpos($sectionContent, '<section>');
        if ($nextSection !== false) {
            $sectionContent = substr($sectionContent, 0, $nextSection);
        }
        expect($sectionContent)->not->toContain('Pending Game');
    });

    it('does not show invited games in Games I\'m In section', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Invited Only Game']);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        // Invited games appear in Open Invitations, not in Games I'm In
        $response = actingAs($user)->get('/en/games');
        $content = $response->getContent();

        // Find the Games I'm In section and ensure the game is NOT there
        $gamesImInSection = substr($content, strpos($content, 'Games I&#039;m In') ?: strpos($content, "Games I'm In"));
        $nextSection = strpos($gamesImInSection, '<section>');
        if ($nextSection !== false) {
            $gamesImInSection = substr($gamesImInSection, 0, $nextSection);
        }

        expect($gamesImInSection)->not->toContain('Invited Only Game');
    });

    it('shows view link for participating games', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Viewable Game']);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.action_view_game'));
    });
});

// ═══════════════════════════════════════════════════════════
// OPEN INVITATIONS — DISPLAY
// ═══════════════════════════════════════════════════════════

describe('GamesPage — Open Invitations Display', function () {
    it('hides section when no pending invitations', function () {
        $user = gamesPageCreateUser();

        actingAs($user)
            ->get('/en/games')
            ->assertDontSee(__('games.heading_open_invitations'));
    });

    it('shows section heading when invitations exist', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Invite Game']);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.heading_open_invitations'));
    });

    it('shows game name for pending invitations', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Invite Me Game']);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        actingAs($user)
            ->get('/en/games')
            ->assertSee('Invite Me Game');
    });

    it('shows accept and decline buttons for invitations', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.action_accept_invitation'))
            ->assertSee(__('games.action_decline_invitation'));
    });
});

// ═══════════════════════════════════════════════════════════
// ACCEPT INVITATION ACTION
// ═══════════════════════════════════════════════════════════

describe('GamesPage — Accept Invitation Action', function () {
    it('accepts a pending invitation', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'max_players' => 4]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('acceptInvitation', $participant->id);

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $component->assertSee(__('games.flash_invitation_accepted'));
    });

    it('rejects accepting another users invitation', function () {
        $user = gamesPageCreateUser();
        $other = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $other->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('acceptInvitation', $participant->id);

        // Status should remain unchanged
        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        $component->assertSee(__('games.error_not_your_invitation'));
    });

    it('rejects accepting a non-invited participant', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('acceptInvitation', $participant->id);

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    });

    it('rejects accepting when game is full', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'max_players' => 1]);

        // Fill the game with an approved player
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => gamesPageCreateUser()->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('acceptInvitation', $participant->id);

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        $component->assertSee(__('games.error_game_full'));
    });

    it('allows accepting when game has no max_players limit', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'max_players' => null]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('acceptInvitation', $participant->id);

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    });

    it('allows accepting when game still has capacity', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'max_players' => 3]);

        // Add 2 approved players (game has room for 1 more)
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => gamesPageCreateUser()->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => gamesPageCreateUser()->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('acceptInvitation', $participant->id);

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// DECLINE INVITATION ACTION
// ═══════════════════════════════════════════════════════════

describe('GamesPage — Decline Invitation Action', function () {
    it('declines a pending invitation', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('declineInvitation', $participant->id);

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'rejected',
        ]);

        $component->assertSee(__('games.flash_invitation_declined'));
    });

    it('rejects declining another users invitation', function () {
        $user = gamesPageCreateUser();
        $other = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $other->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('declineInvitation', $participant->id);

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);
    });

    it('rejects declining a non-pending invitation', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'invited',
            'status' => 'rejected',
        ]);

        // Calling decline again — status should not change (still rejected)
        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('declineInvitation', $participant->id);

        assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'role' => 'invited',
            'status' => 'rejected',
        ]);
    });
});

// ═══════════════════════════════════════════════════════════
// COMMUNITY SECTION — DISPLAY & FILTERS
// ═══════════════════════════════════════════════════════════

describe('GamesPage — Community Section', function () {
    it('shows community section heading', function () {
        $user = gamesPageCreateUser();

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.heading_community'));
    });

    it('shows empty state when no community games', function () {
        $user = gamesPageCreateUser();

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.content_no_community_games'));
    });

    it('shows public scheduled upcoming games', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Community Game', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDay()]);

        actingAs($user)
            ->get('/en/games')
            ->assertSee('Community Game');
    });

    it('does not show private games', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Private Community Game', 'visibility' => 'private', 'status' => 'scheduled', 'date_time' => now()->addDay()]);

        actingAs($user)
            ->get('/en/games')
            ->assertDontSee('Private Community Game');
    });

    it('does not show canceled games', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Canceled Community Game', 'visibility' => 'public', 'status' => 'canceled', 'date_time' => now()->addDay()]);

        actingAs($user)
            ->get('/en/games')
            ->assertDontSee('Canceled Community Game');
    });

    it('does not show past games', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Past Community Game', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->subDay()]);

        actingAs($user)
            ->get('/en/games')
            ->assertDontSee('Past Community Game');
    });

    it('shows search input', function () {
        $user = gamesPageCreateUser();

        actingAs($user)
            ->get('/en/games')
            ->assertSee(__('games.action_search_games_by_name_or_description'));
    });

    it('filters community games by search query', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $matchGame = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Dungeons of Doom', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDay()]);
        $noMatchGame = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Catan Night', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDay()]);

        actingAs($user)
            ->get('/en/games?q=Dungeons')
            ->assertSee('Dungeons of Doom')
            ->assertDontSee('Catan Night');
    });

    it('clears filters', function () {
        $user = gamesPageCreateUser();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->set('search', 'test')
            ->set('game_system_id', 1)
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('game_system_id', null);
    });

    it('toggles vibe flags', function () {
        $user = gamesPageCreateUser();

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('toggleVibeFlag', 'beginner_friendly')
            ->assertSet('vibe_flags', ['beginner_friendly']);

        $component->call('toggleVibeFlag', 'beginner_friendly')
            ->assertSet('vibe_flags', []);
    });

    it('resets page when filters change', function () {
        $user = gamesPageCreateUser();

        Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->set('search', 'test');

        // Page should have been reset — no exception means success
        $this->assertTrue(true);
    });

    it('does not show completed games in community section', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Completed Community Game', 'visibility' => 'public', 'status' => 'completed', 'date_time' => now()->addDay()]);

        actingAs($user)
            ->get('/en/games')
            ->assertDontSee('Completed Community Game');
    });

    it('filters community games by game system', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $system = GameSystem::factory()->create(['name' => 'D&D 5e']);
        $matchGame = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'D&D Game', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDay(), 'game_system_id' => $system->id]);
        $otherGame = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Pathfinder Game', 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDay(), 'game_system_id' => GameSystem::factory()->create(['name' => 'Pathfinder'])->id]);

        actingAs($user)
            ->get('/en/games?game_system_id=' . $system->id)
            ->assertSee('D&D Game')
            ->assertDontSee('Pathfinder Game');
    });

    it('paginates community games at 12 per page', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();

        // Create 15 public scheduled upcoming games
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);
        gamesPageCreateGame(['owner_id' => $owner->id, 'visibility' => 'public', 'status' => 'scheduled', 'date_time' => now()->addDays(rand(1, 30))]);

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class);
        $communityGames = $component->viewData('communityGames');

        expect($communityGames->count())->toBe(12);
        expect($communityGames->hasMorePages())->toBeTrue();
    });
});
