<?php

use App\Models\Event;
use App\Models\User;
use RalphJSmit\Laravel\SEO\Support\SEOData;

describe('Event getDynamicSEOData', function () {
    it('returns title from event name', function () {
        $event = Event::factory()->create([
            'name' => 'Grand Tournament 2025',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo)->toBeInstanceOf(SEOData::class);
        expect($seo->title)->toBe('Grand Tournament 2025');
    });

    it('returns description from short_description when present', function () {
        $event = Event::factory()->create([
            'short_description' => 'Join us for the biggest event of the year!',
            'description' => 'This is a longer description that should be ignored.',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->description)->toBe('Join us for the biggest event of the year!');
    });

    it('falls back to stripped description when short_description is absent', function () {
        $event = Event::factory()->create([
            'short_description' => null,
            'description' => 'A detailed description of the event.',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->description)->toContain('A detailed description of the event.');
    });

    it('limits fallback description to 160 characters', function () {
        $longDescription = str_repeat('Long event description. ', 20);
        $event = Event::factory()->create([
            'short_description' => null,
            'description' => $longDescription,
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $seo = $event->getDynamicSEOData();

        expect(strlen($seo->description))->toBeLessThanOrEqual(163);
    });

    it('returns null description when both description fields are empty', function () {
        $event = Event::factory()->create([
            'short_description' => null,
            'description' => null,
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->description)->toBeNull();
    });

    it('returns fallback image when no media is attached', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->image)->toContain('og-default.jpg');
    });

    it('returns index,follow for public event with registration_open status', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->robots)->toBe('index, follow');
    });

    it('returns index,follow for public event with published status', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'published',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->robots)->toBe('index, follow');
    });

    it('returns index,follow for public event with registration_closed status', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'registration_closed',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->robots)->toBe('index, follow');
    });

    it('returns index,follow for public event with in_progress status', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'in_progress',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->robots)->toBe('index, follow');
    });

    it('returns noindex,nofollow for draft event', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'draft',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->robots)->toBe('noindex, nofollow');
    });

    it('returns noindex,nofollow for non-public event', function () {
        $event = Event::factory()->create([
            'is_public' => false,
            'status' => 'registration_open',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->robots)->toBe('noindex, nofollow');
    });

    it('returns noindex,nofollow for completed event', function () {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => 'completed',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->robots)->toBe('noindex, nofollow');
    });
});
