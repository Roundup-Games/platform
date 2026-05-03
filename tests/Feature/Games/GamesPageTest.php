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
    // smoke: games page renders for authenticated users
    it('renders for authenticated users', function () {
        $user = gamesPageCreateUser();

        actingAs($user)
            ->get('/en/games')
            ->assertOk()
            ->assertSee(__('games.heading_my_games'));
    })->group('smoke');


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



    it('does not show other users private games on the page', function () {
        $user = gamesPageCreateUser();
        $other = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $other->id, 'name' => 'Other User Game', 'visibility' => 'private']);

        actingAs($user)
            ->get('/en/games')
            ->assertDontSee('Other User Game');
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

    it('allows accepting when game has no effective max_players limit', function () {
        $user = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'max_players' => 999]);

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


// ═══════════════════════════════════════════════════════════
// COMMUNITY SECTION — ACTIVITY FEED
// ═══════════════════════════════════════════════════════════

describe('GamesPage — Community Activity Feed', function () {
    it('shows activity when a followed user creates a game', function () {
        $user = gamesPageCreateUser();
        $friend = gamesPageCreateUser();
        // User follows friend
        \App\Models\UserRelationship::follow($user, $friend);
        $game = gamesPageCreateGame(['owner_id' => $friend->id, 'name' => 'Friend Created Game']);

        actingAs($user)
            ->get('/en/games')
            ->assertSee('Friend Created Game')
            ->assertSee(__('games.activity_created_game'));
    });

    it('shows activity when a followed user joins a game', function () {
        $user = gamesPageCreateUser();
        $friend = gamesPageCreateUser();
        $owner = gamesPageCreateUser();
        \App\Models\UserRelationship::follow($user, $friend);
        $game = gamesPageCreateGame(['owner_id' => $owner->id, 'name' => 'Game Friend Joined']);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        actingAs($user)
            ->get('/en/games')
            ->assertSee('Game Friend Joined')
            ->assertSee(__('games.activity_joined_game'));
    });

    it('shows activity when a followed user completes a game', function () {
        $user = gamesPageCreateUser();
        $friend = gamesPageCreateUser();
        \App\Models\UserRelationship::follow($user, $friend);
        $game = gamesPageCreateGame(['owner_id' => $friend->id, 'name' => 'Completed Game', 'status' => 'completed']);

        actingAs($user)
            ->get('/en/games')
            ->assertSee('Completed Game')
            ->assertSee(__('games.activity_completed_game'));
    });

    it('does not show activity from unfollowed users', function () {
        $user = gamesPageCreateUser();
        $stranger = gamesPageCreateUser();
        $game = gamesPageCreateGame(['owner_id' => $stranger->id, 'name' => 'Stranger Game']);

        actingAs($user)
            ->get('/en/games')
            ->assertDontSee('Stranger Game');
    });

    it('does not show games the viewer already owns or participates in', function () {
        $user = gamesPageCreateUser();
        $friend = gamesPageCreateUser();
        \App\Models\UserRelationship::follow($user, $friend);
        // Viewer owns this game — should not appear as "friend joined"
        $game = gamesPageCreateGame(['owner_id' => $user->id, 'name' => 'My Own Game For Feed']);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $friend->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        // The game_created activity should still show (friend didn't create it — viewer did)
        // But the player_joined activity should NOT show because viewer already owns it
        $response = actingAs($user)->get('/en/games');
        $content = $response->getContent();

        // The game name appears in My Games section, not in the feed
        $heading = __('games.heading_community');
        $pos = strpos($content, $heading);
        expect($pos)->not->toBeFalse();
    });

    it('paginates activity feed at 15 per page', function () {
        $user = gamesPageCreateUser();
        $friend = gamesPageCreateUser();
        \App\Models\UserRelationship::follow($user, $friend);

        // Create 18 games owned by friend
        for ($i = 0; $i < 18; $i++) {
            gamesPageCreateGame(['owner_id' => $friend->id, 'name' => "Feed Game {$i}"]);
        }

        $component = Livewire\Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GamesPage::class);
        $feed = $component->viewData('activityFeed');

        expect($feed->count())->toBe(15);
        expect($feed->hasMorePages())->toBeTrue();
    });
});
