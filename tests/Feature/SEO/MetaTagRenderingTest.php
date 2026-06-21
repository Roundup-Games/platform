<?php

use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Game;

use function Pest\Laravel\get;

// Entity-level meta tag coverage lives in EntitySeoTest (model-level) and
// StructuredDataTest (HTTP-level). This file covers static-page meta tags
// and edge cases (HTML stripping, special characters).

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
