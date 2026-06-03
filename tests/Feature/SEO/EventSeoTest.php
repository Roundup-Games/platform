<?php

use App\Models\Event;
use App\Models\User;
use RalphJSmit\Laravel\SEO\Support\SEOData;

describe('Event getDynamicSEOData', function () {
    it('returns title from event name', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Grand Tournament 2025'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo)->toBeInstanceOf(SEOData::class);
        expect($seo->title)->toBe('Grand Tournament 2025');
    });

    it('returns description from short_description when present', function () {
        $event = Event::factory()->create([
            'short_description' => ['en' => 'Join us for the biggest event of the year!'],
            'description' => ['en' => 'This is a longer description that should be ignored.'],
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->description)->toBe('Join us for the biggest event of the year!');
    });

    it('falls back to stripped description when short_description is absent', function () {
        $event = Event::factory()->create([
            'short_description' => null,
            'description' => ['en' => 'A detailed description of the event.'],
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
            'description' => ['en' => $longDescription],
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

    it('returns index,follow for public event with non-terminal status', function ($status) {
        $event = Event::factory()->create([
            'is_public' => true,
            'status' => $status,
        ]);

        $seo = $event->getDynamicSEOData();

        expect($seo->robots)->toBe('index, follow');
    })->with(['registration_open', 'published', 'registration_closed', 'in_progress']);

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
