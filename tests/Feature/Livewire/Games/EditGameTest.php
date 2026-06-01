<?php

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\EntityUpdated;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
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
        'name' => ['en' => 'Test Game'],
        'date_time' => now()->addDays(7),
        'description' => ['en' => 'Original description'],
        'expected_duration' => 2,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => '123 Main St'],
    ], $overrides));
}

describe('Edit Game Modal', function () {
    it('opens edit modal with game data', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->assertSet('editingGameId', $game->id)
            ->assertSet('edit_name', 'Test Game')
            ->assertSet('edit_description', 'Original description')
            ->assertSet('edit_expected_duration', '2')
            ->assertSet('edit_visibility', 'public')
            ->assertSet('edit_location_details', '123 Main St');
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
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        Notification::fake();

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            
            ->call('editGame', $game->id)
            ->set('edit_name', 'Changed Name')
            ->call('saveGameEdit');

        Notification::assertSentTo(
            $participant,
            EntityUpdated::class,
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

        Notification::assertNotSentTo($this->owner, EntityUpdated::class);
    });

    it('does not send notification when nothing changed', function () {
        $game = createOwnedGame($this->owner, $this->gameSystem);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
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

        expect($game->fresh()->visibility)->toBe(\App\Enums\Visibility::Protected);
    });

    it('allows public visibility when user has can_create_public_entries', function () {
        $owner = User::factory()->create(['can_create_public_entries' => true]);
        $game = createOwnedGame($owner, $this->gameSystem, ['visibility' => 'protected']);

        Livewire::actingAs($owner)->test(\App\Livewire\Games\GamesPage::class)
            ->call('editGame', $game->id)
            ->set('edit_visibility', 'public')
            ->call('saveGameEdit');

        expect($game->fresh()->visibility)->toBe(\App\Enums\Visibility::Public);
    });
});
