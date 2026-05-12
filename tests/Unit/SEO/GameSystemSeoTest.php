<?php

use App\Models\GameSystem;
use RalphJSmit\Laravel\SEO\Support\SEOData;

describe('GameSystem getDynamicSEOData', function () {
    it('returns title from model name', function () {
        $system = GameSystem::factory()->create(['name' => 'Dungeons & Dragons 5e']);

        $seo = $system->getDynamicSEOData();

        expect($seo)->toBeInstanceOf(SEOData::class);
        expect($seo->title)->toBe('Dungeons & Dragons 5e');
    });

    it('returns description from model description field', function () {
        $system = GameSystem::factory()->create([
            'description' => 'A fantastic tabletop role-playing game with deep lore.',
        ]);

        $seo = $system->getDynamicSEOData();

        expect($seo->description)->not->toBeNull();
        expect($seo->description)->toContain('A fantastic tabletop role-playing game');
    });

    it('limits description to 160 characters', function () {
        $longDescription = str_repeat('This is a long description. ', 20);
        $system = GameSystem::factory()->create(['description' => $longDescription]);

        $seo = $system->getDynamicSEOData();

        expect(strlen($seo->description))->toBeLessThanOrEqual(163); // 160 + potential ellipsis
    });

    it('returns null description when description is empty', function () {
        $system = GameSystem::factory()->create(['description' => null]);

        $seo = $system->getDynamicSEOData();

        expect($seo->description)->toBeNull();
    });

    it('returns fallback image when no media is attached', function () {
        $system = GameSystem::factory()->create(['thumbnail_url' => null]);

        $seo = $system->getDynamicSEOData();

        expect($seo->image)->toContain('og-default.jpg');
    });

    it('returns thumbnail_url as image when no media is attached', function () {
        $system = GameSystem::factory()->create([
            'thumbnail_url' => 'https://example.com/thumb.jpg',
        ]);

        $seo = $system->getDynamicSEOData();

        expect($seo->image)->toBe('https://example.com/thumb.jpg');
    });

    it('always returns index, follow robots directive', function () {
        $system = GameSystem::factory()->create();

        $seo = $system->getDynamicSEOData();

        expect($seo->robots)->toBe('index, follow');
    });

    it('includes schema with Product type', function () {
        $system = GameSystem::factory()->create(['name' => 'Test RPG']);

        $seo = $system->getDynamicSEOData();

        expect($seo->schema)->not->toBeNull();
        $schemaArray = $seo->schema->toArray();
        $product = collect($schemaArray)->first(fn ($item) => ($item['@type'] ?? null) === 'Product');
        expect($product)->not->toBeNull();
        expect($product['name'])->toBe('Test RPG');
    });

    it('includes Product schema with SKU from model id', function () {
        $system = GameSystem::factory()->create();

        $seo = $system->getDynamicSEOData();

        $schemaArray = $seo->schema->toArray();
        $product = collect($schemaArray)->first(fn ($item) => ($item['@type'] ?? null) === 'Product');
        expect($product)->toHaveKey('sku');
        expect($product['sku'])->toBe((string) $system->id);
    });

    it('registers FAQPage markup when faq_content is present', function () {
        $system = GameSystem::factory()->create([
            'faq_content' => [
                ['question' => 'How many players?', 'answer' => '2-6 players.'],
            ],
        ]);

        $seo = $system->getDynamicSEOData();

        // FAQPage is stored in SchemaCollection::markup, not in push/toArray
        expect($seo->schema)->not->toBeNull();
        expect($seo->schema->markup)->toHaveKey('RalphJSmit\Laravel\SEO\Schema\FaqPageSchema');
    });

    it('does not register FAQPage markup when faq_content is empty', function () {
        $system = GameSystem::factory()->create(['faq_content' => null]);

        $seo = $system->getDynamicSEOData();

        expect($seo->schema->markup)->not->toHaveKey('RalphJSmit\Laravel\SEO\Schema\FaqPageSchema');
    });
});
