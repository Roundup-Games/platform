<?php

use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;
use function Pest\Laravel\{get, actingAs};

// ── GameSystem Detail: Full Meta Tag Rendering ─────────

describe('GameSystem Detail Meta Tags', function () {
    it('renders correct title tag', function () {
        $system = GameSystem::factory()->create(['name' => 'Dungeons & Dragons 5e']);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertPageTitle($response, 'Dungeons & Dragons 5e');
    });

    it('renders meta description from model description', function () {
        $system = GameSystem::factory()->create([
            'name' => 'Test System',
            'description' => 'An exciting tabletop RPG system.',
        ]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();

        expect(extractMetaDescription($response->content()))->toContain('An exciting tabletop RPG system.');
    });

    it('renders og:title with entity name', function () {
        $system = GameSystem::factory()->create(['name' => 'OG Title System']);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertOgMetaTag($response, 'og:title', 'OG Title System');
    });

    it('renders og:description meta tag', function () {
        $system = GameSystem::factory()->create([
            'name' => 'OG Desc System',
            'description' => 'OG description content',
        ]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertOgMetaTag($response, 'og:description', 'OG description content');
    });

    it('renders og:image meta tag', function () {
        $system = GameSystem::factory()->create();

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertOgMetaTagPresent($response, 'og:image');
    });

    it('renders og:url meta tag', function () {
        $system = GameSystem::factory()->create(['slug' => 'test-system-og']);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertOgMetaTagPresent($response, 'og:url');
    });

    it('renders og:site_name meta tag', function () {
        $system = GameSystem::factory()->create();

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertOgMetaTag($response, 'og:site_name', 'Roundup Games');
    });

    it('renders twitter:card meta tag', function () {
        $system = GameSystem::factory()->create();

        get(route('game-systems.show', $system->slug))
            ->assertOk()
            ->assertSee('twitter:card', false);
    });

    it('renders canonical link tag', function () {
        $system = GameSystem::factory()->create();

        get(route('game-systems.show', $system->slug))
            ->assertOk()
            ->assertSee('rel="canonical"', false);
    });
});

// ── Event Detail: Full Meta Tag Rendering ──────────────

describe('Event Detail Meta Tags', function () {
    it('renders correct title tag', function () {
        $event = Event::factory()->create([
            'name' => 'Grand Tournament 2025',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $response = get(route('events.detail', $event->slug));
        $response->assertOk();
        assertPageTitle($response, 'Grand Tournament 2025');
    });

    it('renders meta description from short_description', function () {
        $event = Event::factory()->create([
            'name' => 'Described Event',
            'short_description' => 'Join us for the biggest event of the year!',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $response = get(route('events.detail', $event->slug));
        $response->assertOk();

        expect(extractMetaDescription($response->content()))->toContain('Join us for the biggest event of the year!');
    });

    it('renders og:title with entity name', function () {
        $event = Event::factory()->create([
            'name' => 'OG Event',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $response = get(route('events.detail', $event->slug));
        $response->assertOk();
        assertOgMetaTag($response, 'og:title', 'OG Event');
    });

    it('renders og:image meta tag', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $response = get(route('events.detail', $event->slug));
        $response->assertOk();
        assertOgMetaTagPresent($response, 'og:image');
    });

    it('renders twitter:card meta tag', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        get(route('events.detail', $event->slug))
            ->assertOk()
            ->assertSee('twitter:card', false);
    });

    it('renders canonical link tag', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        get(route('events.detail', $event->slug))
            ->assertOk()
            ->assertSee('rel="canonical"', false);
    });
});

// ── Game Detail: Full Meta Tag Rendering ───────────────

describe('Game Detail Meta Tags', function () {
    it('renders correct title tag', function () {
        $game = Game::factory()->create([
            'name' => 'Epic Board Game Night',
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();
        assertPageTitle($response, 'Epic Board Game Night');
    });

    it('renders meta description from game description', function () {
        $game = Game::factory()->create([
            'name' => 'Fun Game',
            'description' => 'An exciting evening of board games for all skill levels.',
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        expect(extractMetaDescription($response->content()))->toContain('An exciting evening of board games');
    });

    it('renders og:title with entity name', function () {
        $game = Game::factory()->create([
            'name' => 'OG Game',
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();
        assertOgMetaTag($response, 'og:title', 'OG Game');
    });

    it('renders og:description meta tag', function () {
        $game = Game::factory()->create([
            'name' => 'OG Desc Game',
            'description' => 'OG game description content',
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();
        assertOgMetaTag($response, 'og:description', 'OG game description content');
    });

    it('renders og:image meta tag', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();
        assertOgMetaTagPresent($response, 'og:image');
    });

    it('renders twitter:card meta tag', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        get(route('games.detail', $game->id))
            ->assertOk()
            ->assertSee('twitter:card', false);
    });

    it('renders canonical link tag', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        get(route('games.detail', $game->id))
            ->assertOk()
            ->assertSee('rel="canonical"', false);
    });
});

// ── Campaign Detail: Full Meta Tag Rendering ───────────

describe('Campaign Detail Meta Tags', function () {
    it('renders correct title tag', function () {
        $campaign = Campaign::factory()->create([
            'name' => 'Curse of Strahd Campaign',
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();
        assertPageTitle($response, 'Curse of Strahd Campaign');
    });

    it('renders meta description from campaign description', function () {
        $campaign = Campaign::factory()->create([
            'name' => 'Described Campaign',
            'description' => 'A gothic horror adventure set in the land of Barovia.',
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();

        expect(extractMetaDescription($response->content()))->toContain('A gothic horror adventure');
    });

    it('renders og:title with entity name', function () {
        $campaign = Campaign::factory()->create([
            'name' => 'OG Campaign',
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();
        assertOgMetaTag($response, 'og:title', 'OG Campaign');
    });

    it('renders og:image meta tag', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();
        assertOgMetaTagPresent($response, 'og:image');
    });

    it('renders twitter:card meta tag', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertSee('twitter:card', false);
    });

    it('renders canonical link tag', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        get(route('campaigns.detail', $campaign->id))
            ->assertOk()
            ->assertSee('rel="canonical"', false);
    });
});

// ── Team Detail: Full Meta Tag Rendering ───────────────

describe('Team Detail Meta Tags', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        actingAs($this->user);
    });

    it('renders correct title tag', function () {
        $team = Team::factory()->create([
            'name' => 'Dragon Slayers',
            'is_active' => true,
        ]);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();
        assertPageTitle($response, 'Dragon Slayers');
    });

    it('renders meta description from team description', function () {
        $team = Team::factory()->create([
            'name' => 'Described Team',
            'description' => 'A competitive tabletop gaming team.',
            'is_active' => true,
        ]);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();

        expect(extractMetaDescription($response->content()))->toContain('A competitive tabletop gaming team.');
    });

    it('renders og:title with entity name', function () {
        $team = Team::factory()->create([
            'name' => 'OG Team',
            'is_active' => true,
        ]);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();
        assertOgMetaTag($response, 'og:title', 'OG Team');
    });

    it('renders og:image meta tag', function () {
        $team = Team::factory()->create(['is_active' => true]);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();
        assertOgMetaTagPresent($response, 'og:image');
    });

    it('renders twitter:card meta tag', function () {
        $team = Team::factory()->create(['is_active' => true]);

        get(route('teams.detail', $team->slug))
            ->assertOk()
            ->assertSee('twitter:card', false);
    });

    it('renders canonical link tag', function () {
        $team = Team::factory()->create(['is_active' => true]);

        get(route('teams.detail', $team->slug))
            ->assertOk()
            ->assertSee('rel="canonical"', false);
    });
});

// ── Public Profile: Full Meta Tag Rendering ────────────

describe('Public Profile Meta Tags', function () {
    it('renders correct title tag', function () {
        $user = User::factory()->create([
            'name' => 'Alice the Gamer',
            'profile_complete' => true,
        ]);

        $response = get(route('profile.public', $user));
        $response->assertOk();
        assertPageTitle($response, 'Alice the Gamer');
    });

    it('renders meta description from user bio', function () {
        $user = User::factory()->create([
            'name' => 'Bio User',
            'bio' => 'I love playing board games and RPGs.',
            'profile_complete' => true,
        ]);

        $response = get(route('profile.public', $user));
        $response->assertOk();

        expect(extractMetaDescription($response->content()))->toContain('I love playing board games and RPGs.');
    });

    it('renders og:title with entity name', function () {
        $user = User::factory()->create([
            'name' => 'OG User',
            'profile_complete' => true,
        ]);

        $response = get(route('profile.public', $user));
        $response->assertOk();
        assertOgMetaTag($response, 'og:title', 'OG User');
    });

    it('renders og:image meta tag', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
        ]);

        $response = get(route('profile.public', $user));
        $response->assertOk();
        assertOgMetaTagPresent($response, 'og:image');
    });

    it('renders twitter:card meta tag', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
        ]);

        get(route('profile.public', $user))
            ->assertOk()
            ->assertSee('twitter:card', false);
    });

    it('renders canonical link tag', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
        ]);

        get(route('profile.public', $user))
            ->assertOk()
            ->assertSee('rel="canonical"', false);
    });
});

// ── Static Pages: Full Meta Tag Rendering ──────────────

describe('Static Pages Meta Tags', function () {
    it('renders how-it-works page with title and description', function () {
        $response = get(route('how-it-works'));
        $response->assertOk();
        assertPageTitle($response, __('pages.seo_title_how_it_works'));
        assertOgMetaTagPresent($response, 'og:title');
        assertOgMetaTagPresent($response, 'og:image');
        $response->assertSee('twitter:card', false)->assertSee('rel="canonical"', false);
    });

    it('renders for-organizers page with title and description', function () {
        $response = get(route('for-organizers'));
        $response->assertOk();
        assertPageTitle($response, __('pages.seo_title_for_organizers'));
        assertOgMetaTagPresent($response, 'og:title');
        $response->assertSee('rel="canonical"', false);
    });

    it('renders contact page with title', function () {
        $response = get(route('contact'));
        $response->assertOk();
        assertPageTitle($response, __('pages.seo_title_contact'));
        assertOgMetaTagPresent($response, 'og:title');
    });

    it('renders safety-tools page with title', function () {
        $response = get(route('safety-tools'));
        $response->assertOk();
        assertPageTitle($response, __('safety.seo_title_safety_tools'));
        assertOgMetaTagPresent($response, 'og:title');
    });

    it('renders discover page with title and description', function () {
        $response = get(route('discover'));
        $response->assertOk();
        assertPageTitle($response, __('discovery.seo_title_discover'));
        expect(extractMetaDescription($response->content()))->toContain(__('discovery.seo_description_discover'));
        assertOgMetaTagPresent($response, 'og:title');
        $response->assertSee('twitter:card', false)->assertSee('rel="canonical"', false);
    });

    it('renders GM directory with title and description', function () {
        $response = get(route('gm.directory'));
        $response->assertOk();
        assertPageTitle($response, __('gms.seo_title_gm_directory'));
        expect(extractMetaDescription($response->content()))->toContain(__('gms.seo_description_gm_directory'));
        assertOgMetaTagPresent($response, 'og:title');
        $response->assertSee('twitter:card', false)->assertSee('rel="canonical"', false);
    });
});

// ── Edge Cases: Special Characters and HTML Stripping ──

describe('Edge Cases', function () {
    it('strips HTML tags from game description in meta tag', function () {
        $game = Game::factory()->create([
            'name' => 'HTML Game',
            'description' => '<p>Join us for <strong>epic</strong> gaming!</p><br>Sign up now.',
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        $description = extractMetaDescription($response->content());
        expect($description)->not->toContain('<p>', '<strong>', '<br>');
        expect($description)->toContain('epic gaming');
    });

    it('strips HTML tags from campaign description in meta tag', function () {
        $campaign = Campaign::factory()->create([
            'name' => 'HTML Campaign',
            'description' => '<h2>Dark Horror</h2><p>A <em>thrilling</em> adventure</p>',
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();

        $description = extractMetaDescription($response->content());
        expect($description)->not->toContain('<h2>', '<p>', '<em>');
        expect($description)->toContain('thrilling adventure');
    });

    it('handles ampersand in game name correctly', function () {
        $game = Game::factory()->create([
            'name' => 'Catan & Carcassonne Night',
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();
        assertPageTitle($response, 'Catan & Carcassonne Night');
    });

    it('handles ampersand in sitemap XML correctly', function () {
        $game = Game::factory()->create([
            'name' => 'Catan & Carcassonne',
            'visibility' => Visibility::Public,
        ]);

        $response = get('/sitemap-games.xml');
        $response->assertOk();

        // Must be valid XML despite special chars in entity names
        $xml = simplexml_load_string($response->content());
        expect($xml)->not->toBeFalse();
    });

    it('handles double quotes in description', function () {
        $game = Game::factory()->create([
            'name' => 'Quoted Game',
            'description' => 'A "fantastic" evening of games',
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();

        // Should render without breaking the meta tag — description extraction should succeed
        $description = extractMetaDescription($response->content());
        expect($description)->toContain('fantastic');
    });

    it('gracefully handles empty description', function () {
        $game = Game::factory()->create([
            'name' => 'No Desc Game',
            'description' => '',
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();
        // Page should still render correctly — title should be present
        assertPageTitle($response, 'No Desc Game');
    });
});
