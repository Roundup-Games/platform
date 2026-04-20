<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

describe('GameSystemDetail - mount and resolution', function () {
    it('resolves game system by slug and renders', function () {
        $system = GameSystem::factory()->create([
            'name' => 'Wingspan',
            'slug' => 'wingspan',
            'description' => 'A competitive bird-collection engine-building game',
        ]);

        get("/en/game-systems/{$system->slug}")
            ->assertOk()
            ->assertSee('Wingspan')
            ->assertSee('A competitive bird-collection engine-building game');
    });

    it('returns 404 for invalid slug', function () {
        get('/en/game-systems/nonexistent-slug-xyz')
            ->assertNotFound();
    });

    it('returns 404 for valid-format slug that does not exist', function () {
        get('/en/game-systems/this-slug-does-not-exist-at-all')
            ->assertNotFound();
    });
});

describe('GameSystemDetail - eager loaded relationships', function () {
    it('eager loads categories', function () {
        $system = GameSystem::factory()->create(['slug' => 'cat-game']);
        $category = GameSystemCategory::create(['name' => 'Strategy', 'slug' => 'strategy']);
        $system->categories()->attach($category);

        get("/en/game-systems/{$system->slug}")
            ->assertOk()
            ->assertSee('Strategy');
    });

    it('eager loads mechanics', function () {
        $system = GameSystem::factory()->create(['slug' => 'mech-game']);
        $mechanic = GameSystemMechanic::create(['name' => 'Deck Building', 'slug' => 'deck-building']);
        $system->mechanics()->attach($mechanic);

        get("/en/game-systems/{$system->slug}")
            ->assertOk()
            ->assertSee('Deck Building');
    });

    it('eager loads expansions for base game', function () {
        $base = GameSystem::factory()->create([
            'slug' => 'base-game',
            'name' => 'Base Game',
        ]);
        $expansion = GameSystem::factory()->create([
            'name' => 'Base Game: Expansion 1',
            'base_game_id' => $base->id,
            'slug' => 'base-game-expansion-1',
        ]);

        get("/en/game-systems/{$base->slug}")
            ->assertOk()
            ->assertSee('Base Game: Expansion 1');
    });

    it('eager loads base game for expansion', function () {
        $base = GameSystem::factory()->create([
            'slug' => 'base-parent',
            'name' => 'Parent Game',
        ]);
        $expansion = GameSystem::factory()->create([
            'name' => 'Child Expansion',
            'base_game_id' => $base->id,
            'slug' => 'child-expansion',
        ]);

        get("/en/game-systems/{$expansion->slug}")
            ->assertOk()
            ->assertSee('Parent Game');
    });

    it('counts active sessions correctly', function () {
        $system = GameSystem::factory()->create(['slug' => 'session-game']);
        $owner = User::factory()->create();

        // Active public session
        Game::factory()->create([
            'game_system_id' => $system->id,
            'owner_id' => $owner->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'date_time' => now()->addDays(3),
        ]);

        // Active protected session
        Game::factory()->create([
            'game_system_id' => $system->id,
            'owner_id' => $owner->id,
            'status' => 'scheduled',
            'visibility' => 'protected',
            'date_time' => now()->addDays(3),
        ]);

        // Past session - should not count
        Game::factory()->create([
            'game_system_id' => $system->id,
            'owner_id' => $owner->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'date_time' => now()->subDays(3),
        ]);

        // Private session - should not count
        Game::factory()->create([
            'game_system_id' => $system->id,
            'owner_id' => $owner->id,
            'status' => 'scheduled',
            'visibility' => 'private',
            'date_time' => now()->addDays(3),
        ]);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->assertSetStrict('sessionCampaignStats', function ($stats) {
                return $stats['active_sessions'] === 2;
            });
    });

    it('counts active campaigns correctly', function () {
        $system = GameSystem::factory()->create(['slug' => 'campaign-game']);
        $owner = User::factory()->create();

        Campaign::factory()->create([
            'game_system_id' => $system->id,
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);

        Campaign::factory()->create([
            'game_system_id' => $system->id,
            'owner_id' => $owner->id,
            'status' => 'completed',
        ]);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->assertSetStrict('sessionCampaignStats', function ($stats) {
                return $stats['active_campaigns'] === 1;
            });
    });
});

describe('GameSystemDetail - user preference counts', function () {
    it('computes favorited_by_count', function () {
        $system = GameSystem::factory()->create(['slug' => 'faved-game']);
        $users = User::factory()->count(3)->create();

        foreach ($users as $user) {
            $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);
        }

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->assertSetStrict('favoritedCount', 3);
    });

    it('computes avoided_by_count', function () {
        $system = GameSystem::factory()->create(['slug' => 'avoided-game']);
        $users = User::factory()->count(2)->create();

        foreach ($users as $user) {
            $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'avoid']);
        }

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->assertSetStrict('avoidedCount', 2);
    });

    it('returns zero counts for systems with no preferences', function () {
        $system = GameSystem::factory()->create(['slug' => 'no-prefs-game']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->assertSetStrict('favoritedCount', 0)
            ->assertSetStrict('avoidedCount', 0);
    });
});

describe('GameSystemDetail - user preference property', function () {
    it('returns null for guests', function () {
        $system = GameSystem::factory()->create(['slug' => 'guest-pref-game']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->assertSetStrict('userPreference', null);
    });

    it('returns null for auth user with no preference', function () {
        $system = GameSystem::factory()->create(['slug' => 'no-pref-auth-game']);
        $user = User::factory()->create();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->assertSetStrict('userPreference', null);
    });

    it('returns favorite for user who favorited the system', function () {
        $system = GameSystem::factory()->create(['slug' => 'faved-auth-game']);
        $user = User::factory()->create();
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->assertSetStrict('userPreference', 'favorite');
    });

    it('returns avoid for user who avoided the system', function () {
        $system = GameSystem::factory()->create(['slug' => 'avoided-auth-game']);
        $user = User::factory()->create();
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'avoid']);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->assertSetStrict('userPreference', 'avoid');
    });
});

describe('GameSystemDetail - toggleFavorite', function () {
    it('adds favorite for auth user', function () {
        $system = GameSystem::factory()->create(['slug' => 'toggle-fav-game']);
        $user = User::factory()->create();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->call('toggleFavorite');

        $this->assertDatabaseHas('user_game_system_preferences', [
            'user_id' => $user->id,
            'game_system_id' => $system->id,
            'preference_type' => 'favorite',
        ]);
    });

    it('removes existing favorite on second toggle', function () {
        $system = GameSystem::factory()->create(['slug' => 'remove-fav-game']);
        $user = User::factory()->create();
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->call('toggleFavorite');

        $this->assertDatabaseMissing('user_game_system_preferences', [
            'user_id' => $user->id,
            'game_system_id' => $system->id,
        ]);
    });

    it('replaces avoid with favorite', function () {
        $system = GameSystem::factory()->create(['slug' => 'replace-avoid-fav']);
        $user = User::factory()->create();
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'avoid']);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->call('toggleFavorite');

        $this->assertDatabaseHas('user_game_system_preferences', [
            'user_id' => $user->id,
            'game_system_id' => $system->id,
            'preference_type' => 'favorite',
        ]);
        $this->assertDatabaseMissing('user_game_system_preferences', [
            'user_id' => $user->id,
            'game_system_id' => $system->id,
            'preference_type' => 'avoid',
        ]);
    });

    it('rejects unauthenticated toggleFavorite', function () {
        $system = GameSystem::factory()->create(['slug' => 'guest-fav-game']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->call('toggleFavorite')
            ->assertStatus(403);
    });
});

describe('GameSystemDetail - toggleAvoid', function () {
    it('adds avoid for auth user', function () {
        $system = GameSystem::factory()->create(['slug' => 'toggle-avoid-game']);
        $user = User::factory()->create();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->call('toggleAvoid');

        $this->assertDatabaseHas('user_game_system_preferences', [
            'user_id' => $user->id,
            'game_system_id' => $system->id,
            'preference_type' => 'avoid',
        ]);
    });

    it('removes existing avoid on second toggle', function () {
        $system = GameSystem::factory()->create(['slug' => 'remove-avoid-game']);
        $user = User::factory()->create();
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'avoid']);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->call('toggleAvoid');

        $this->assertDatabaseMissing('user_game_system_preferences', [
            'user_id' => $user->id,
            'game_system_id' => $system->id,
        ]);
    });

    it('replaces favorite with avoid', function () {
        $system = GameSystem::factory()->create(['slug' => 'replace-fav-avoid']);
        $user = User::factory()->create();
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->call('toggleAvoid');

        $this->assertDatabaseHas('user_game_system_preferences', [
            'user_id' => $user->id,
            'game_system_id' => $system->id,
            'preference_type' => 'avoid',
        ]);
        $this->assertDatabaseMissing('user_game_system_preferences', [
            'user_id' => $user->id,
            'game_system_id' => $system->id,
            'preference_type' => 'favorite',
        ]);
    });

    it('rejects unauthenticated toggleAvoid', function () {
        $system = GameSystem::factory()->create(['slug' => 'guest-avoid-game']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->call('toggleAvoid')
            ->assertStatus(403);
    });
});

describe('GameSystemDetail - sessionCampaignStats helper', function () {
    it('returns zeros for system with no sessions or campaigns', function () {
        $system = GameSystem::factory()->create(['slug' => 'empty-stats-game']);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->assertSetStrict('sessionCampaignStats', [
                'active_sessions' => 0,
                'active_campaigns' => 0,
            ]);
    });

    it('returns correct counts for active sessions and campaigns', function () {
        $system = GameSystem::factory()->create(['slug' => 'stats-game']);
        $owner = User::factory()->create();

        Game::factory()->count(3)->create([
            'game_system_id' => $system->id,
            'owner_id' => $owner->id,
            'status' => 'scheduled',
            'visibility' => 'public',
            'date_time' => now()->addDays(5),
        ]);

        Campaign::factory()->count(2)->create([
            'game_system_id' => $system->id,
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);

        Livewire\Livewire::test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->assertSetStrict('sessionCampaignStats', [
                'active_sessions' => 3,
                'active_campaigns' => 2,
            ]);
    });
});

describe('GameSystemDetail - dispatches events', function () {
    it('dispatches preference-updated event on toggleFavorite', function () {
        $system = GameSystem::factory()->create(['slug' => 'dispatch-fav-game']);
        $user = User::factory()->create();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->call('toggleFavorite')
            ->assertDispatched('preference-updated');
    });

    it('dispatches preference-updated event on toggleAvoid', function () {
        $system = GameSystem::factory()->create(['slug' => 'dispatch-avoid-game']);
        $user = User::factory()->create();

        Livewire\Livewire::actingAs($user)
            ->test(App\Livewire\GameSystems\GameSystemDetail::class, ['slug' => $system->slug])
            ->call('toggleAvoid')
            ->assertDispatched('preference-updated');
    });
});
