<?php

use App\Enums\CampaignStatus;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\GameSystem;
use RalphJSmit\Laravel\SEO\Support\SEOData;

describe('Campaign getDynamicSEOData', function () {
    it('returns title from campaign name', function () {
        $campaign = Campaign::factory()->create([
            'name' => ['en' => 'Curse of Strahd Campaign'],
            'visibility' => Visibility::Public,
        ]);

        $seo = $campaign->getDynamicSEOData();

        expect($seo)->toBeInstanceOf(SEOData::class);
        expect($seo->title)->toBe('Curse of Strahd Campaign');
    });

    it('returns description from campaign description', function () {
        $campaign = Campaign::factory()->create([
            'description' => ['en' => 'A gothic horror adventure set in the land of Barovia.'],
            'visibility' => Visibility::Public,
        ]);

        $seo = $campaign->getDynamicSEOData();

        expect($seo->description)->toContain('A gothic horror adventure');
    });

    it('limits description to 160 characters', function () {
        $longDescription = str_repeat('A very long campaign description. ', 20);
        $campaign = Campaign::factory()->create([
            'description' => ['en' => $longDescription],
            'visibility' => Visibility::Public,
        ]);

        $seo = $campaign->getDynamicSEOData();

        expect(strlen($seo->description))->toBeLessThanOrEqual(163);
    });

    it('returns fallback image when no images or game system media', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Public,
        ]);

        $seo = $campaign->getDynamicSEOData();

        expect($seo->image)->toContain('og-default.jpg');
    });

    it('returns game system cover image as fallback when no campaign images', function () {
        $system = GameSystem::factory()->create([
            'thumbnail_url' => 'https://example.com/system-cover.jpg',
        ]);
        $campaign = Campaign::factory()->create([
            'game_system_id' => $system->id,
            'images' => null,
            'visibility' => Visibility::Public,
        ]);

        $seo = $campaign->getDynamicSEOData();

        expect($seo->image)->toBe('https://example.com/system-cover.jpg');
    });

    it('returns index,follow for public visibility campaign', function () {
        $campaign = Campaign::factory()->create(['visibility' => Visibility::Public]);

        $seo = $campaign->getDynamicSEOData();

        expect($seo->robots)->toBe('index, follow');
    });

    it('returns noindex,nofollow for private visibility campaign', function () {
        $campaign = Campaign::factory()->create(['visibility' => Visibility::Private]);

        $seo = $campaign->getDynamicSEOData();

        expect($seo->robots)->toBe('noindex, nofollow');
    });

    it('returns noindex,nofollow for protected visibility campaign', function () {
        $campaign = Campaign::factory()->create(['visibility' => Visibility::Protected]);

        $seo = $campaign->getDynamicSEOData();

        expect($seo->robots)->toBe('noindex, nofollow');
    });

    it('does not include schema for non-public campaign', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Private,
            'status' => CampaignStatus::Active->value,
        ]);

        $seo = $campaign->getDynamicSEOData();

        expect($seo->schema)->toBeNull();
    });

    it('does not include schema for public but cancelled campaign', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Public,
            'status' => CampaignStatus::Cancelled->value,
        ]);

        $seo = $campaign->getDynamicSEOData();

        expect($seo->schema)->toBeNull();
    });

    it('includes Event schema for public active campaign', function () {
        $campaign = Campaign::factory()->create([
            'visibility' => Visibility::Public,
            'status' => CampaignStatus::Active->value,
        ]);

        $seo = $campaign->getDynamicSEOData();

        expect($seo->schema)->not->toBeNull();
        $schemaArray = $seo->schema->toArray();
        $event = collect($schemaArray)->first(fn ($item) => ($item['@type'] ?? null) === 'Event');
        expect($event)->not->toBeNull();
    });
});
