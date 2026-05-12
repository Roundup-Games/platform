<?php

use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;
use function Pest\Laravel\{get, actingAs};

// ── Game Visibility Robots ─────────────────────────────

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

    it('renders noindex, nofollow for private game visible to owner', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'visibility' => Visibility::Private,
            'owner_id' => $owner->id,
        ]);

        actingAs($owner);
        get(route('games.detail', $game->id))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);
    });

    it('renders noindex, nofollow for protected game visible to owner', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'visibility' => Visibility::Protected,
            'owner_id' => $owner->id,
        ]);

        actingAs($owner);
        get(route('games.detail', $game->id))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);
    });

    it('renders robots meta tag containing correct content attribute', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        expect($matches[1] ?? '')->toBe('index, follow');
    });

    it('private game robots meta tag contains noindex, nofollow', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'visibility' => Visibility::Private,
            'owner_id' => $owner->id,
        ]);

        actingAs($owner);
        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        expect($matches[1] ?? '')->toBe('noindex, nofollow');
    });
});

// ── Campaign Visibility Robots ─────────────────────────

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

    it('renders noindex, nofollow for private campaign visible to owner', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Private,
            'owner_id' => $owner->id,
        ]);

        actingAs($owner);
        get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);
    });

    it('renders noindex, nofollow for protected campaign visible to owner', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Protected,
            'owner_id' => $owner->id,
        ]);

        actingAs($owner);
        get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);
    });

    it('renders robots meta tag with correct content attribute for public campaign', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        expect($matches[1] ?? '')->toBe('index, follow');
    });

    it('renders robots meta tag with noindex, nofollow for private campaign', function () {
        $owner = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Private,
            'owner_id' => $owner->id,
        ]);

        actingAs($owner);
        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        expect($matches[1] ?? '')->toBe('noindex, nofollow');
    });
});

// ── Event Visibility Robots ────────────────────────────

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

    it('renders index, follow for public event with registration_closed status', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_closed',
        ]);

        get(route('events.detail', $event->slug))
            ->assertOk()
            ->assertSee('index, follow', false);
    });

    it('renders index, follow for public event with in_progress status', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'in_progress',
        ]);

        get(route('events.detail', $event->slug))
            ->assertOk()
            ->assertSee('index, follow', false);
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

    it('renders robots meta tag with correct content for public event', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $response = get(route('events.detail', $event->slug));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        expect($matches[1] ?? '')->toBe('index, follow');
    });

    it('renders robots meta tag with noindex, nofollow for draft event', function () {
        $owner = User::factory()->create();
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'draft',
            'organizer_id' => $owner->id,
        ]);

        actingAs($owner);
        $response = get(route('events.detail', $event->slug));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        expect($matches[1] ?? '')->toBe('noindex, nofollow');
    });
});

// ── Team Visibility Robots ─────────────────────────────

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

    it('renders robots meta tag with correct content for active team', function () {
        $user = User::factory()->create();
        actingAs($user);

        $team = Team::factory()->create(['is_active' => true]);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        expect($matches[1] ?? '')->toBe('index, follow');
    });
});

// ── GameSystem Robots (always indexable) ───────────────

describe('GameSystem Robots', function () {
    it('renders index, follow for all game systems', function () {
        $system = GameSystem::factory()->create();

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        expect($matches[1] ?? '')->toBe('index, follow');
    });
});

// ── Profile Visibility Robots ──────────────────────────

describe('Profile Visibility Robots', function () {
    it('renders index, follow for profile with visible fields', function () {
        $user = User::factory()->create([
            'name' => 'Visible User',
            'bio' => 'Check out my profile',
            'profile_complete' => true,
        ]);

        $response = get(route('profile.public', $user));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        // Profile with bio has visible fields, so should be indexable
        expect($matches[1] ?? '')->toBe('index, follow');
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

        $response = get(route('profile.public', $user));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        expect($matches[1] ?? '')->toBe('noindex, nofollow');
    });
});

// ── Admin Override Robots Precedence ───────────────────

describe('Admin Override Robots Precedence', function () {
    it('admin noindex override takes precedence over dynamic index', function () {
        $system = GameSystem::factory()->create(['name' => 'Override Test']);
        $system->seo->update(['robots' => 'noindex, nofollow']);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        expect($matches[1] ?? '')->toBe('noindex, nofollow');
    });

    it('clearing robots override restores dynamic value', function () {
        $system = GameSystem::factory()->create(['name' => 'Clear Override']);
        $system->seo->update(['robots' => 'noindex, nofollow']);

        // Verify override applied
        get(route('game-systems.show', $system->slug))
            ->assertOk()
            ->assertSee('noindex, nofollow', false);

        // Clear override
        $system->seo->update(['robots' => null]);

        // Verify dynamic value restored
        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        expect($matches[1] ?? '')->toBe('index, follow');
    });

    it('admin noindex override on public game takes precedence', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
        ]);
        $game->seo->update(['robots' => 'noindex, nofollow']);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        $content = $response->content();
        preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', $content, $matches);
        expect($matches[1] ?? '')->toBe('noindex, nofollow');
    });
});
