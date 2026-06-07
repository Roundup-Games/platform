<?php

use App\Enums\VenueType;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Location;
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

function createGameWithVenue(User $owner, GameSystem $system, array $overrides = []): Game
{
    return Game::create(array_merge([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => ['en' => 'Test Game'],
        'date_time' => now()->addDays(7),
        'description' => ['en' => 'Original description'],
        'expected_duration' => 2,
        'visibility' => 'protected',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => '123 Main St'],
    ], $overrides));
}

function createGameWithLocation(User $owner, GameSystem $system, ?Location $location = null): Game
{
    return createGameWithVenue($owner, $system, [
        'location_id' => $location?->id,
    ]);
}

// ── Finding 1: No duplicate "Location" label in notification ──

describe('Location change notification dedup', function () {
    it('does not produce duplicate Location label when both location.details and location_id change', function () {
        $location = Location::factory()->create(['name' => 'Old Venue', 'city' => 'Berlin']);
        $game = createGameWithLocation($this->owner, $this->gameSystem, $location);

        $newVenue = Location::factory()->create([
            'name' => 'New Venue',
            'city' => 'Munich',
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
        ]);

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
            ->set('edit_location_details', 'Changed details text')
            ->set('edit_location_id', $newVenue->id)
            ->call('saveGameEdit');

        // Verify notification has Location only once
        Notification::assertSentTo(
            $participant,
            EntityUpdated::class,
            function (EntityUpdated $notification) {
                $locationCount = count(array_filter(
                    $notification->changedFields,
                    fn (string $field) => $field === __('common.field_location'),
                ));
                return $locationCount === 1;
            }
        );
    });

    it('adds Location label when only location.details text changes', function () {
        $location = Location::factory()->create(['name' => 'Venue', 'city' => 'Berlin']);
        $game = createGameWithLocation($this->owner, $this->gameSystem, $location);

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
            ->set('edit_location_details', 'New details text')
            ->call('saveGameEdit');

        Notification::assertSentTo(
            $participant,
            EntityUpdated::class,
            fn (EntityUpdated $notification) => in_array(__('common.field_location'), $notification->changedFields)
        );
    });

    it('adds Location label when only location_id changes', function () {
        $oldLocation = Location::factory()->create(['name' => 'Old Venue', 'city' => 'Berlin']);
        $game = createGameWithLocation($this->owner, $this->gameSystem, $oldLocation);

        $newVenue = Location::factory()->create([
            'name' => 'New Venue',
            'city' => 'Munich',
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
        ]);

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
            ->set('edit_location_id', $newVenue->id)
            ->call('saveGameEdit');

        Notification::assertSentTo(
            $participant,
            EntityUpdated::class,
            fn (EntityUpdated $notification) => in_array(__('common.field_location'), $notification->changedFields)
        );
    });

    it('adds location_instructions to changes but not to changedLabels', function () {
        $game = createGameWithVenue($this->owner, $this->gameSystem);

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
            ->set('edit_location_instructions', 'Ring the bell twice')
            ->call('saveGameEdit');

        // Instructions update is saved but does not trigger a notification label
        $freshGame = $game->fresh();
        expect($freshGame->location_instructions)->toBe('Ring the bell twice');
        Notification::assertNotSentTo($participant, EntityUpdated::class);
    });
});

// ── Finding 3: Location data pre-loaded from controller, not queried in blade ──

describe('Location data pre-loaded in edit modal', function () {
    it('pre-loads location name/city/address when opening edit modal', function () {
        $location = Location::factory()->create([
            'name' => 'Board Game Cafe',
            'city' => 'Berlin',
            'address' => 'Main St 5',
        ]);
        $game = createGameWithLocation($this->owner, $this->gameSystem, $location);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            ->call('editGame', $game->id)
            ->assertSet('edit_location_name', 'Board Game Cafe')
            ->assertSet('edit_location_city', 'Berlin')
            ->assertSet('edit_location_address', 'Main St 5');
    });

    it('handles null location gracefully in edit modal', function () {
        $game = createGameWithLocation($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            ->call('editGame', $game->id)
            ->assertSet('edit_location_name', '')
            ->assertSet('edit_location_city', '')
            ->assertSet('edit_location_address', '');
    });
});

// ── Finding 5: Implicit auth guard on venue actions ──

describe('Venue action auth guard', function () {
    it('venue search actions require active edit context', function () {
        // Seed a verified venue so the guard is actually tested —
        // without it, the test passes because search returns empty regardless.
        Location::factory()->create([
            'name' => 'Should Not Appear',
            'city' => 'Berlin',
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
            'latitude' => 52.52,
            'longitude' => 13.40,
        ]);

        $component = Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class);

        // These should silently no-op without an active edit context
        $component
            ->set('edit_venue_query', 'Should Not Appear')
            ->call('editSearchVenues')
            ->assertSet('edit_venue_results', [])
            ->call('editClearLocation')
            ->assertSet('edit_location_id', null);
    });
});

// ── Trait integration: venue actions work through the trait ──

describe('EditsVenueLocation trait integration', function () {
    it('selects a verified venue via editSelectVenue', function () {
        $game = createGameWithLocation($this->owner, $this->gameSystem);

        $venue = Location::factory()->create([
            'name' => 'Test Venue',
            'city' => 'Berlin',
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
        ]);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            ->call('editGame', $game->id)
            ->call('editSelectVenue', $venue->id)
            ->assertSet('edit_location_id', $venue->id)
            ->assertSet('edit_location_name', 'Test Venue')
            ->assertSet('edit_location_city', 'Berlin');
    });

    it('ignores non-verified location in editSelectVenue', function () {
        $game = createGameWithLocation($this->owner, $this->gameSystem);

        $unverified = Location::factory()->create([
            'name' => 'Not Verified',
            'city' => 'Berlin',
            'is_verified' => false,
        ]);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            ->call('editGame', $game->id)
            ->call('editSelectVenue', $unverified->id)
            ->assertSet('edit_location_id', $game->location_id); // unchanged
    });

    it('creates a new Location via editSaveAddress', function () {
        $game = createGameWithLocation($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            ->call('editGame', $game->id)
            ->set('edit_address_mode', 'address')
            ->set('edit_address_city', 'Hamburg')
            ->set('edit_address_street', 'Elbstrasse 12')
            ->call('editSaveAddress')
            ->assertSet('edit_location_name', 'Elbstrasse 12, Hamburg')
            ->assertSet('edit_location_city', 'Hamburg');

        // Verify Location was created with source=manual
        $newLocation = Location::where('city', 'Hamburg')
            ->where('source', 'manual')
            ->first();
        expect($newLocation)->not->toBeNull();
        expect($newLocation->address)->toBe('Elbstrasse 12');
    });

    it('clears location state via editClearLocation', function () {
        $location = Location::factory()->create(['name' => 'Venue', 'city' => 'Berlin']);
        $game = createGameWithLocation($this->owner, $this->gameSystem, $location);

        Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            ->call('editGame', $game->id)
            ->call('editClearLocation')
            ->assertSet('edit_location_id', null)
            ->assertSet('edit_location_name', '')
            ->assertSet('edit_location_city', '');
    });

    it('returns venue search results as arrays not stdClass', function () {
        $game = createGameWithLocation($this->owner, $this->gameSystem);

        Location::factory()->create([
            'name' => 'Board Game Cafe',
            'city' => 'Munich',
            'is_verified' => true,
            'venue_type' => VenueType::Cafe,
        ]);

        $result = Livewire::actingAs($this->owner)->test(\App\Livewire\Games\GamesPage::class)
            ->call('editGame', $game->id)
            ->set('edit_venue_query', 'Board')
            ->call('editSearchVenues')
            ->assertSet('edit_venue_searched', true)
            ->get('edit_venue_results');

        expect($result)->toHaveCount(1);
        expect($result[0])->toBeArray();
        expect($result[0])->toHaveKeys(['id', 'name', 'city', 'address', 'venue_type', 'distance_km']);
        expect($result[0]['name'])->toBe('Board Game Cafe');
    });
});
