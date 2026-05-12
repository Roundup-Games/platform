<?php

use App\Models\Team;
use App\Models\User;
use RalphJSmit\Laravel\SEO\Support\SEOData;

describe('Team getDynamicSEOData', function () {
    it('returns title from team name', function () {
        $team = Team::factory()->create(['name' => 'Dragon Slayers']);

        $seo = $team->getDynamicSEOData();

        expect($seo)->toBeInstanceOf(SEOData::class);
        expect($seo->title)->toBe('Dragon Slayers');
    });

    it('returns description from team description field', function () {
        $team = Team::factory()->create([
            'description' => 'A competitive tabletop gaming team.',
            'is_active' => true,
        ]);

        $seo = $team->getDynamicSEOData();

        expect($seo->description)->toContain('A competitive tabletop gaming team');
    });

    it('limits description to 160 characters', function () {
        $longDescription = str_repeat('A very long team description. ', 20);
        $team = Team::factory()->create([
            'description' => $longDescription,
            'is_active' => true,
        ]);

        $seo = $team->getDynamicSEOData();

        expect(strlen($seo->description))->toBeLessThanOrEqual(163);
    });

    it('returns composite description from name, city, and country when no description', function () {
        $team = Team::factory()->create([
            'description' => null,
            'name' => 'Dragon Slayers',
            'city' => 'Berlin',
            'country' => 'DE',
            'is_active' => true,
        ]);

        $seo = $team->getDynamicSEOData();

        expect($seo->description)->toContain('Dragon Slayers');
        expect($seo->description)->toContain('Berlin');
        expect($seo->description)->toContain('DE');
    });

    it('returns composite description from name and city when no description or country', function () {
        $team = Team::factory()->create([
            'description' => null,
            'name' => 'Dragon Slayers',
            'city' => 'Berlin',
            'country' => null,
            'is_active' => true,
        ]);

        $seo = $team->getDynamicSEOData();

        expect($seo->description)->toContain('Dragon Slayers');
        expect($seo->description)->toContain('Berlin');
    });

    it('returns name-only description when no description, city, or country', function () {
        $team = Team::factory()->create([
            'description' => null,
            'name' => 'Dragon Slayers',
            'city' => null,
            'country' => null,
            'is_active' => true,
        ]);

        $seo = $team->getDynamicSEOData();

        expect($seo->description)->toBe('Dragon Slayers');
    });

    it('returns fallback image when no media is attached', function () {
        $team = Team::factory()->create(['is_active' => true]);

        $seo = $team->getDynamicSEOData();

        expect($seo->image)->toContain('og-default.jpg');
    });

    it('returns index,follow for active team', function () {
        $team = Team::factory()->create(['is_active' => true]);

        $seo = $team->getDynamicSEOData();

        expect($seo->robots)->toBe('index, follow');
    });

    it('returns noindex,nofollow for inactive team', function () {
        $team = Team::factory()->create(['is_active' => false]);

        $seo = $team->getDynamicSEOData();

        expect($seo->robots)->toBe('noindex, nofollow');
    });

    it('includes Organization schema for active team', function () {
        $team = Team::factory()->create([
            'name' => 'Dragon Slayers',
            'is_active' => true,
        ]);

        $seo = $team->getDynamicSEOData();

        expect($seo->schema)->not->toBeNull();
        $schemaArray = $seo->schema->toArray();
        $org = collect($schemaArray)->first(fn ($item) => ($item['@type'] ?? null) === 'Organization');
        expect($org)->not->toBeNull();
        expect($org['name'])->toBe('Dragon Slayers');
    });

    it('does not include schema for inactive team', function () {
        $team = Team::factory()->create(['is_active' => false]);

        $seo = $team->getDynamicSEOData();

        expect($seo->schema)->toBeNull();
    });

    it('includes address in Organization schema when city and country are set', function () {
        $team = Team::factory()->create([
            'is_active' => true,
            'city' => 'Berlin',
            'country' => 'DE',
        ]);

        $seo = $team->getDynamicSEOData();

        $schemaArray = $seo->schema->toArray();
        $org = collect($schemaArray)->first(fn ($item) => ($item['@type'] ?? null) === 'Organization');
        expect($org)->toHaveKey('address');
    });

    it('includes foundingDate in Organization schema when founded_year is set', function () {
        $team = Team::factory()->create([
            'is_active' => true,
            'founded_year' => 2020,
        ]);

        $seo = $team->getDynamicSEOData();

        $schemaArray = $seo->schema->toArray();
        $org = collect($schemaArray)->first(fn ($item) => ($item['@type'] ?? null) === 'Organization');
        expect($org['foundingDate'])->toBe('2020');
    });

    it('includes sameAs social links in Organization schema', function () {
        $team = Team::factory()->create([
            'is_active' => true,
            'website' => 'https://example.com',
            'social_links' => ['https://twitter.com/team'],
        ]);

        $seo = $team->getDynamicSEOData();

        $schemaArray = $seo->schema->toArray();
        $org = collect($schemaArray)->first(fn ($item) => ($item['@type'] ?? null) === 'Organization');
        expect($org)->toHaveKey('sameAs');
        expect($org['sameAs'])->toContain('https://example.com');
        expect($org['sameAs'])->toContain('https://twitter.com/team');
    });
});
