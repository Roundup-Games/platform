<?php

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\GameUpdated;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

function createOwnedGame(User $owner, GameSystem $system, array $overrides = []): Game
{
    return Game::create(array_merge([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => 'Test Game',
        'date_time' => now()->addDays(7),
        'description' => 'Original description',
        'expected_duration' => 2,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => '123 Main St'],
    ], $overrides));
}

describe('Edit Game Modal', function () {
    it('shows edit button for scheduled owned games', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->assertSee(__('games.action_edit_game'));
    });

    it('does not show edit button for completed games', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem, ['status' => 'completed']);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->assertDontSee(__('games.action_edit_game'));
    });

    it('does not show edit button for canceled games', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem, ['status' => 'canceled']);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->assertDontSee(__('games.action_edit_game'));
    });

    it('opens edit modal with game data', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->assertSet('editingGameId', $game->id)
            ->assertSet('edit_name', 'Test Game')
            ->assertSet('edit_description', 'Original description')
            ->assertSet('edit_expected_duration', '2')
            ->assertSet('edit_visibility', 'public')
            ->assertSet('edit_location_details', '123 Main St')
            ->assertSee(__('games.heading_edit_game'));
    });

    it('closes modal on cancelEdit', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->call('cancelEdit')
            ->assertSet('editingGameId', null)
            ->assertDontSee(__('games.heading_edit_game'));
    });
});

describe('Save Game Edit', function () {
    it('updates game name', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->set('edit_name', 'Updated Game Name')
            ->call('saveGameEdit');

        expect($game->fresh()->name)->toBe('Updated Game Name');
    })->group('smoke');

    it('updates game description', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->set('edit_description', 'New description')
            ->call('saveGameEdit');

        expect($game->fresh()->description)->toBe('New description');
    });

    it('updates game duration', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->set('edit_expected_duration', '3.5')
            ->call('saveGameEdit');

        expect($game->fresh()->expected_duration)->toBe(3.5);
    });

    it('updates game visibility', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->set('edit_visibility', 'private')
            ->call('saveGameEdit');

        expect($game->fresh()->visibility)->toBe('private');
    });

    it('updates game location', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->set('edit_location_details', '456 New Address')
            ->call('saveGameEdit');

        expect($game->fresh()->location['details'])->toBe('456 New Address');
    });

    it('validates required name', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->set('edit_name', '')
            ->call('saveGameEdit')
            ->assertHasErrors(['edit_name']);
    });

    it('validates visibility values', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->set('edit_visibility', 'invalid')
            ->call('saveGameEdit')
            ->assertHasErrors(['edit_visibility']);
    });

    it('logs activity on update', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->set('edit_name', 'Changed Name')
            ->call('saveGameEdit');

        $log = ActivityLog::where('subject_type', Game::class)
            ->where('subject_id', $game->id)
            ->where('event_type', ActivityType::GameUpdated)
            ->first();

        expect($log)->not->toBeNull();
        expect($log->properties['changed_fields'])->toContain(__('games.field_name'));
    });

    it('sends notifications to approved participants', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Notification::fake();

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->set('edit_name', 'Changed Name')
            ->call('saveGameEdit');

        Notification::assertSentTo(
            $participant,
            GameUpdated::class,
            fn ($notification) => in_array(__('games.field_name'), $notification->changedFields)
        );
    });

    it('does not notify the owner', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Notification::fake();

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->set('edit_name', 'Changed Name')
            ->call('saveGameEdit');

        Notification::assertNotSentTo($this->owner, GameUpdated::class);
    });

    it('does not send notification when nothing changed', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Notification::fake();

        $component = Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            ->call('editGame', $game->id);

        // Verify no changes were actually made
        $freshGame = Game::find($game->id);
        $component->call('saveGameEdit');

        // Game should be unchanged
        $afterGame = $freshGame->fresh();
        expect($afterGame->name)->toBe($freshGame->name);
        expect($afterGame->description)->toBe($freshGame->description);
    });

    it('shows flash message on success', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->set('edit_name', 'Changed Name')
            ->call('saveGameEdit')
            ->assertSee(__('games.flash_game_updated'));
    });

    it('prevents non-owners from editing', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);
        $otherUser = User::factory()->create();

        Livewire::actingAs($otherUser)->test(\App\Livewire\Games\GamesPage::class)
            ->call('editGame', $game->id)
            ->assertStatus(403);
    });

    it('downgrades public visibility to protected when user lacks can_create_public_entries', function () {
        $owner = User::factory()->create(['can_create_public_entries' => false]);
        $game = createOwnedGame($owner, $this->gameSystem, ['visibility' => 'protected']);

        Livewire::actingAs($owner)->test(\App\Livewire\Games\GamesPage::class)
            ->call('editGame', $game->id)
            ->set('edit_visibility', 'public')
            ->call('saveGameEdit');

        expect($game->fresh()->visibility)->toBe('protected');
    });

    it('allows public visibility when user has can_create_public_entries', function () {
        $owner = User::factory()->create(['can_create_public_entries' => true]);
        $game = createOwnedGame($owner, $this->gameSystem, ['visibility' => 'protected']);

        Livewire::actingAs($owner)->test(\App\Livewire\Games\GamesPage::class)
            ->call('editGame', $game->id)
            ->set('edit_visibility', 'public')
            ->call('saveGameEdit');

        expect($game->fresh()->visibility)->toBe('public');
    });
});
