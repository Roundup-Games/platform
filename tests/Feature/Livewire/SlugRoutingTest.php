<?php

use App\Models\User;
use Illuminate\Support\Str;

describe('Slug-based profile routing', function () {
    it('generates slug-based URLs via route helper', function () {
        $user = User::factory()->create(['name' => 'John Doe']);

        $url = route('profile.public', ['locale' => 'en', 'user' => $user]);

        expect($url)->toContain('/u/john-doe');
        expect($user->slug)->toBe('john-doe');
    });

    it('resolves profile by slug via route model binding', function () {
        $user = User::factory()->create([
            'name' => 'Jane Smith',
            'profile_complete' => true,
        ]);

        // Simulate HTTP GET to slug URL
        $response = $this->get(route('profile.public', ['locale' => 'en', 'user' => $user]));

        $response->assertOk();
        $response->assertSee('Jane Smith');
    });

    it('redirects UUID URLs to canonical slug URL (301)', function () {
        $user = User::factory()->create([
            'name' => 'Legacy User',
            'profile_complete' => true,
        ]);

        // Access via UUID instead of slug
        $url = '/'.app()->getLocale().'/u/'.$user->id;

        $response = $this->get($url);

        $response->assertRedirect();
        expect($response->getStatusCode())->toBe(301);
        $response->assertRedirectContains('/u/'.$user->slug);
    });

    it('returns 404 for non-existent slug', function () {
        $response = $this->get('/'.app()->getLocale().'/u/nonexistent-user-slug');

        $response->assertNotFound();
    });

    it('returns 404 for non-existent UUID', function () {
        $fakeUuid = (string) Str::uuid();

        $response = $this->get('/'.app()->getLocale().'/u/'.$fakeUuid);

        $response->assertStatus(404);
    });

    it('generates unique slugs for same-named users', function () {
        $user1 = User::factory()->create(['name' => 'Test User']);
        $user2 = User::factory()->create(['name' => 'Test User']);

        expect($user1->slug)->not->toBe($user2->slug);
        expect($user1->slug)->toBe('test-user');
        expect($user2->slug)->toBe('test-user-2');

        // Both URLs should resolve to their respective users
        $url1 = route('profile.public', ['locale' => 'en', 'user' => $user1]);
        $url2 = route('profile.public', ['locale' => 'en', 'user' => $user2]);

        expect($url1)->toContain('/u/test-user');
        expect($url2)->toContain('/u/test-user-2');
    });

    it('auto-generates slug in model creating hook when slug is empty', function () {
        $user = User::factory()->create(['name' => 'Auto Slug User']);

        expect($user->slug)->not->toBeNull();
        expect($user->slug)->toBe('auto-slug-user');
    });

    it('does not overwrite explicit slug set before creation', function () {
        $user = User::factory()->create([
            'name' => 'Custom Name',
            'slug' => 'custom-explicit-slug',
        ]);

        expect($user->slug)->toBe('custom-explicit-slug');
    });
});
