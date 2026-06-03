<?php

use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;
use function Pest\Laravel\{get, actingAs};

// Per-entity visibility robots tests. The *SeoTest.php files unit-test
// getDynamicSEOData() for every status/visibility combination. These
// integration tests verify that the HTTP response actually renders the
// correct robots meta tag for the key visibility states.

describe('Game Visibility Robots', function () {
    it('renders index, follow for public game', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        get(route('games.detail', $game->id))
            ->assertOk()
            ->assertSee('index, follow', false)
            ->assertDontSee('noindex', false);
    });

    it('redirects authenticated owner from public route to dashboard for private game', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'visibility' => Visibility::Private,
            'owner_id' => $owner->id,
        ]);

        actingAs($owner);
        get(route('games.detail', $game->id))
            ->assertRedirect(route('games.show', $game->id));
    });

    it('redirects authenticated owner from public route to dashboard for protected game', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'visibility' => Visibility::Protected,
            'owner_id' => $owner->id,
        ]);

        actingAs($owner);
        get(route('games.detail', $game->id))
            ->assertRedirect(route('games.show', $game->id));
    });
});

describe('Campaign Visibility Robots', function () {
    it('renders index, follow for public campaign', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertSee('index, follow', false)
            ->assertDontSee('noindex', false);
    });

    it('redirects authenticated owner from public route to dashboard for private campaign', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Private,
            'owner_id' => $owner->id,
        ]);

        actingAs($owner);
        get(route('campaigns.detail', $campaign->id))
            ->assertRedirect(route('campaigns.show', $campaign->id));
    });

    it('redirects authenticated owner from public route to dashboard for protected campaign', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Protected,
            'owner_id' => $owner->id,
        ]);

        actingAs($owner);
        get(route('campaigns.detail', $campaign->id))
            ->assertRedirect(route('campaigns.show', $campaign->id));
    });
});

describe('Event Visibility Robots', function () {
    it('renders index, follow for public published event', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        get(route('events.detail', $event->slug))
            ->assertOk()
            ->assertSee('index, follow', false)
            ->assertDontSee('noindex', false);
    });

    it('renders noindex, nofollow for draft event visible to owner', function () {
        $owner = User::factory()->create();
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'draft',
            'organizer_id' => $owner->id,
        ]);

        actingAs($owner);
        get(route('events.detail', $event->slug))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);
    });

    it('renders noindex, nofollow for non-public event visible to owner', function () {
        $owner = User::factory()->create();
        $event = Event::factory()->create([
            'is_public' => false,
            'status' => 'registration_open',
            'organizer_id' => $owner->id,
        ]);

        actingAs($owner);
        get(route('events.detail', $event->slug))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);
    });

    it('renders noindex, nofollow for cancelled event visible to owner', function () {
        $owner = User::factory()->create();
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'cancelled',
            'organizer_id' => $owner->id,
        ]);

        actingAs($owner);
        get(route('events.detail', $event->slug))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);
    });
});

describe('Team Visibility Robots', function () {
    it('renders index, follow for active team', function () {
        $user = User::factory()->create();
        actingAs($user);

        $team = Team::factory()->create(['is_active' => true]);

        get(route('teams.detail', $team->slug))
            ->assertOk()
            ->assertSee('index, follow', false)
            ->assertDontSee('noindex', false);
    });

    it('renders noindex, nofollow for inactive team visible to member', function () {
        $creator = User::factory()->create();
        $team = Team::factory()->create(['is_active' => false, 'created_by' => $creator->id]);
        $team->members()->create([
            'user_id' => $creator->id,
            'role' => 'captain',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        actingAs($creator);
        get(route('teams.detail', $team->slug))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);
    });
});

describe('GameSystem Robots', function () {
    it('renders index, follow for all game systems', function () {
        $system = GameSystem::factory()->create();

        get(route('game-systems.show', $system->slug))
            ->assertOk()
            ->assertSee('index, follow', false);
    });
});

describe('Profile Visibility Robots', function () {
    it('renders index, follow for profile with visible fields', function () {
        $user = User::factory()->create([
            'name' => 'Visible User',
            'bio' => 'Check out my profile',
            'profile_complete' => true,
        ]);

        get(route('profile.public', $user))
            ->assertOk()
            ->assertSee('index, follow', false);
    });

    it('renders noindex, nofollow for profile with no guest-visible fields', function () {
        $user = User::factory()->create([
            'name' => 'Hidden User',
            'bio' => null,
            'profile_complete' => true,
            'privacy_settings' => [
                'location' => 'nobody',
                'game_systems' => 'nobody',
                'vibes' => 'nobody',
                'campaigns' => 'nobody',
                'teams' => 'nobody',
                'friends_list' => 'nobody',
                'stats' => 'nobody',
            ],
        ]);

        get(route('profile.public', $user))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);
    });
});

describe('Admin Override Robots Precedence', function () {
    it('admin noindex override takes precedence over dynamic index', function () {
        $system = GameSystem::factory()->create(['name' => ['en' => 'Override Test']]);
        $system->seo->update(['robots' => 'noindex, nofollow']);

        get(route('game-systems.show', $system->slug))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);
    });

    it('clearing robots override restores dynamic value', function () {
        $system = GameSystem::factory()->create(['name' => ['en' => 'Clear Override']]);
        $system->seo->update(['robots' => 'noindex, nofollow']);

        // Verify override applied
        get(route('game-systems.show', $system->slug))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);

        // Clear override
        $system->seo->update(['robots' => null]);

        // Verify dynamic value restored
        get(route('game-systems.show', $system->slug))
            ->assertOk()
            ->assertSee('index, follow', false);
    });

    it('admin noindex override on public game takes precedence', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
        ]);
        $game->seo->update(['robots' => 'noindex, nofollow']);

        get(route('games.detail', $game->id))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);
    });
});
