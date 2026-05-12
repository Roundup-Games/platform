<?php

use App\Enums\Visibility;
use App\Models\Game;
use App\Models\GameSystem;
use RalphJSmit\Laravel\SEO\Support\SEOData;

describe('Game getDynamicSEOData', function () {
    it('returns title from game name', function () {
        $game = Game::factory()->create([
            'name' => 'Epic Board Game Night',
            'visibility' => Visibility::Public,
        ]);

        $seo = $game->getDynamicSEOData();

        expect($seo)->toBeInstanceOf(SEOData::class);
        expect($seo->title)->toBe('Epic Board Game Night');
    });

    it('returns description from game description', function () {
        $game = Game::factory()->create([
            'description' => 'An exciting evening of board games for all skill levels.',
            'visibility' => Visibility::Public,
        ]);

        $seo = $game->getDynamicSEOData();

        expect($seo->description)->toContain('An exciting evening of board games');
    });

    it('limits description to 160 characters', function () {
        $longDescription = str_repeat('A very long game description. ', 20);
        $game = Game::factory()->create([
            'description' => $longDescription,
            'visibility' => Visibility::Public,
        ]);

        $seo = $game->getDynamicSEOData();

        expect(strlen($seo->description))->toBeLessThanOrEqual(163);
    });

    it('returns null description from empty string description', function () {
        $game = Game::factory()->create([
            'description' => '',
            'visibility' => Visibility::Public,
        ]);

        $seo = $game->getDynamicSEOData();

        // Str::limit(strip_tags('')) returns '' which is falsy, so description is null
        expect($seo->description)->toBeNull();
    });

    it('returns fallback image when no game system media exists', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        $seo = $game->getDynamicSEOData();

        expect($seo->image)->toContain('og-default.jpg');
    });

    it('returns game system cover image as fallback', function () {
        $system = GameSystem::factory()->create([
            'thumbnail_url' => 'https://example.com/system-cover.jpg',
        ]);
        $game = Game::factory()->create([
            'game_system_id' => $system->id,
            'visibility' => Visibility::Public,
        ]);

        $seo = $game->getDynamicSEOData();

        expect($seo->image)->toBe('https://example.com/system-cover.jpg');
    });

    it('returns index,follow for public visibility game', function () {
        $game = Game::factory()->create(['visibility' => Visibility::Public]);

        $seo = $game->getDynamicSEOData();

        expect($seo->robots)->toBe('index, follow');
    });

    it('returns noindex,nofollow for private visibility game', function () {
        $game = Game::factory()->create(['visibility' => Visibility::Private]);

        $seo = $game->getDynamicSEOData();

        expect($seo->robots)->toBe('noindex, nofollow');
    });

    it('returns noindex,nofollow for protected visibility game', function () {
        $game = Game::factory()->create(['visibility' => Visibility::Protected]);

        $seo = $game->getDynamicSEOData();

        expect($seo->robots)->toBe('noindex, nofollow');
    });

    it('includes Event schema for public game with date_time', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
            'date_time' => now()->addDays(7),
        ]);

        $seo = $game->getDynamicSEOData();

        expect($seo->schema)->not->toBeNull();
        $schemaArray = $seo->schema->toArray();
        $event = collect($schemaArray)->first(fn ($item) => ($item['@type'] ?? null) === 'Event');
        expect($event)->not->toBeNull();
        expect($event)->toHaveKey('startDate');
    });

    it('does not include schema for private game', function () {
        $game = Game::factory()->create([
            'visibility' => Visibility::Private,
            'date_time' => now()->addDays(7),
        ]);

        $seo = $game->getDynamicSEOData();

        expect($seo->schema)->toBeNull();
    });

    it('does not include schema for public game without date_time (factory always sets date_time)', function () {
        // Since date_time is NOT NULL, all games have a date_time.
        // Verify schema IS present for public games (factory provides date_time).
        $game = Game::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        $seo = $game->getDynamicSEOData();

        expect($seo->schema)->not->toBeNull();
    });
});
