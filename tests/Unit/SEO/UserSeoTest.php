<?php

use App\Models\User;
use App\Services\ProfileVisibilityResolver;
use RalphJSmit\Laravel\SEO\Support\SEOData;

describe('User getDynamicSEOData', function () {
    it('returns title from user name', function () {
        $user = User::factory()->create(['name' => 'Alice the Gamer']);

        $seo = $user->getDynamicSEOData();

        expect($seo)->toBeInstanceOf(SEOData::class);
        expect($seo->title)->toBe('Alice the Gamer');
    });

    it('returns description from user bio', function () {
        $user = User::factory()->create([
            'name' => 'Bio User',
            'bio' => 'I love playing board games and RPGs.',
        ]);

        $seo = $user->getDynamicSEOData();

        expect($seo->description)->toContain('I love playing board games and RPGs');
    });

    it('limits bio description to 160 characters', function () {
        $longBio = str_repeat('I love playing games. ', 20);
        $user = User::factory()->create([
            'name' => 'Long Bio User',
            'bio' => $longBio,
        ]);

        $seo = $user->getDynamicSEOData();

        expect(strlen($seo->description))->toBeLessThanOrEqual(163);
    });

    it('returns default description when user has no bio', function () {
        $user = User::factory()->create([
            'name' => 'NoBio User',
            'bio' => null,
        ]);

        $seo = $user->getDynamicSEOData();

        expect($seo->description)->toContain('NoBio User');
        expect($seo->description)->toContain('profile on Roundup Games');
    });

    it('returns fallback image when no avatar media or url', function () {
        $user = User::factory()->create([
            'avatar_url' => null,
        ]);

        $seo = $user->getDynamicSEOData();

        expect($seo->image)->toContain('og-default.jpg');
    });

    it('returns avatar_url as image when no media is attached', function () {
        $user = User::factory()->create([
            'avatar_url' => 'https://example.com/avatar.jpg',
        ]);

        $seo = $user->getDynamicSEOData();

        expect($seo->image)->toBe('https://example.com/avatar.jpg');
    });

    it('returns index,follow when guest can see profile fields', function () {
        $user = User::factory()->create();

        // Mock the resolver to return visible fields for guest
        $this->mock(ProfileVisibilityResolver::class, function ($mock) use ($user) {
            $mock->shouldReceive('profileFieldsVisible')
                ->with(null, $user)
                ->andReturn(['name', 'bio', 'avatar']);
        });

        $seo = $user->getDynamicSEOData();

        expect($seo->robots)->toBe('index, follow');
    });

    it('returns noindex,nofollow when guest cannot see profile fields', function () {
        $user = User::factory()->create();

        // Mock the resolver to return empty fields for guest
        $this->mock(ProfileVisibilityResolver::class, function ($mock) use ($user) {
            $mock->shouldReceive('profileFieldsVisible')
                ->with(null, $user)
                ->andReturn([]);
        });

        $seo = $user->getDynamicSEOData();

        expect($seo->robots)->toBe('noindex, nofollow');
    });

    it('includes Person schema for indexable profiles', function () {
        $user = User::factory()->create(['name' => 'Schema User']);

        $this->mock(ProfileVisibilityResolver::class, function ($mock) use ($user) {
            $mock->shouldReceive('profileFieldsVisible')
                ->with(null, $user)
                ->andReturn(['name', 'bio']);
        });

        $seo = $user->getDynamicSEOData();

        expect($seo->schema)->not->toBeNull();
        $schemaArray = $seo->schema->toArray();
        $person = collect($schemaArray)->first(fn ($item) => ($item['@type'] ?? null) === 'Person');
        expect($person)->not->toBeNull();
        expect($person['name'])->toBe('Schema User');
    });

    it('does not include schema for non-indexable profiles', function () {
        $user = User::factory()->create();

        $this->mock(ProfileVisibilityResolver::class, function ($mock) use ($user) {
            $mock->shouldReceive('profileFieldsVisible')
                ->with(null, $user)
                ->andReturn([]);
        });

        $seo = $user->getDynamicSEOData();

        expect($seo->schema)->toBeNull();
    });
});
