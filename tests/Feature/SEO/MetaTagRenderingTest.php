<?php

use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\User;

use function Pest\Laravel\get;

// ── Entity Smoke Tests: one per entity to verify route+SEO wiring ────
// Full getDynamicSEOData() coverage is in *SeoTest.php files.
// These smoke tests ensure the HTTP route renders meta tags correctly.

describe('Entity SEO smoke tests', function () {
    it('renders meta tags for game system detail page', function () {
        $system = GameSystem::factory()->create(['name' => ['en' => 'Smoke System']]);

        $response = get(route('game-systems.show', $system->slug));
        $response->assertOk();
        assertPageTitle($response, 'Smoke System');
        $response->assertSee('twitter:card', false);
    });

    it('renders meta tags for event detail page', function () {
        $event = Event::factory()->create([
            'name' => 'Smoke Event',
            'is_public' => true,
            'status' => 'published',
        ]);

        $response = get(route('events.detail', $event->slug));
        $response->assertOk();
        assertPageTitle($response, 'Smoke Event');
    });

    it('renders meta tags for game detail page', function () {
        $game = Game::factory()->create([
            'name' => ['en' => 'Smoke Game'],
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();
        assertPageTitle($response, 'Smoke Game');
    });

    it('renders meta tags for campaign detail page', function () {
        $campaign = Campaign::factory()->create([
            'name' => ['en' => 'Smoke Campaign'],
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('campaigns.detail', $campaign->id));
        $response->assertOk();
        assertPageTitle($response, 'Smoke Campaign');
    });

    it('renders meta tags for team detail page', function () {
        $team = Team::factory()->create(['is_active' => true]);

        $response = get(route('teams.detail', $team->slug));
        $response->assertOk();
        $response->assertSee('twitter:card', false)->assertSee('rel="canonical"', false);
    });

    it('renders meta tags for public profile page', function () {
        $user = User::factory()->create([
            'name' => 'Smoke User',
            'profile_complete' => true,
        ]);

        $response = get(route('profile.public', $user));
        $response->assertOk();
        assertPageTitle($response, 'Smoke User');
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
            'name' => ['en' => 'HTML Game'],
            'description' => ['en' => '<p>Join us for <strong>epic</strong> gaming!</p><br>Sign up now.'],
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
            'name' => ['en' => 'HTML Campaign'],
            'description' => ['en' => '<h2>Dark Horror</h2><p>A <em>thrilling</em> adventure</p>'],
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
            'name' => ['en' => 'Catan & Carcassonne Night'],
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();
        assertPageTitle($response, 'Catan & Carcassonne Night');
    });

    it('handles ampersand in sitemap XML correctly', function () {
        $game = Game::factory()->create([
            'name' => ['en' => 'Catan & Carcassonne'],
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
            'name' => ['en' => 'Quoted Game'],
            'description' => ['en' => 'A "fantastic" evening of games'],
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
            'name' => ['en' => 'No Desc Game'],
            'description' => ['en' => ''],
            'visibility' => Visibility::Public,
        ]);

        $response = get(route('games.detail', $game->id));
        $response->assertOk();
        // Page should still render correctly — title should be present
        assertPageTitle($response, 'No Desc Game');
    });
});
