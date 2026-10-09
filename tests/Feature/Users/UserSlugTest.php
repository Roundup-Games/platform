<?php

use App\Models\User;

describe('User::generateSlug', function () {
    it('converts a simple name to a lowercase hyphenated slug', function () {
        expect(User::generateSlug('John Doe'))->toBe('john-doe');
    });

    it('handles multiple spaces', function () {
        expect(User::generateSlug('John   Doe  Smith'))->toBe('john-doe-smith');
    });

    it('handles hyphens in name', function () {
        expect(User::generateSlug('Mary-Jane Watson'))->toBe('mary-jane-watson');
    });

    it('lowercases the result', function () {
        expect(User::generateSlug('UPPER CASE NAME'))->toBe('upper-case-name');
    });

    it('strips emojis', function () {
        expect(User::generateSlug('John 🎮 Doe'))->toBe('john-doe');
    });

    it('strips special characters', function () {
        expect(User::generateSlug('John@Doe#Test'))->toBe('johndoetest');
    });

    it('transliterates unicode letters to ASCII with German expansions', function () {
        $slug = User::generateSlug('François Müller');
        // ü correctly expands to ue (German transliteration), not just u
        expect($slug)->toBe('francois-mueller');
    });

    it('strips leading and trailing hyphens', function () {
        expect(User::generateSlug('  John Doe  '))->toBe('john-doe');
    });

    it('collapses consecutive hyphens', function () {
        expect(User::generateSlug('John--Doe'))->toBe('john-doe');
    });

    it('returns empty string for names with only special chars', function () {
        expect(User::generateSlug('@#$%^'))->toBe('');
    });

    it('handles single word name', function () {
        expect(User::generateSlug('Johnathan'))->toBe('johnathan');
    });
});

describe('User::generateUniqueSlug', function () {
    it('returns the base slug when no collision exists', function () {
        $slug = User::generateUniqueSlug('John Doe');

        expect($slug)->toBe('john-doe');
    });

    it('appends -2 when a collision exists', function () {
        User::factory()->create(['name' => 'John Doe', 'slug' => 'john-doe']);

        $slug = User::generateUniqueSlug('John Doe');

        expect($slug)->toBe('john-doe-2');
    });

    it('appends incrementing numbers for multiple collisions', function () {
        User::factory()->create(['name' => 'John Doe', 'slug' => 'john-doe']);
        User::factory()->create(['name' => 'John Doe', 'slug' => 'john-doe-2']);

        $slug = User::generateUniqueSlug('John Doe');

        expect($slug)->toBe('john-doe-3');
    });

    it('ignores a specific user id for self-reference', function () {
        $user = User::factory()->create(['name' => 'John Doe', 'slug' => 'john-doe']);

        $slug = User::generateUniqueSlug('John Doe', $user->id);

        expect($slug)->toBe('john-doe');
    });

    it('falls back to user when name produces empty slug', function () {
        $slug = User::generateUniqueSlug('@#$%^');

        expect($slug)->toBe('user');
    });

    it('handles collision on fallback user slug', function () {
        User::factory()->create(['name' => 'user', 'slug' => 'user']);

        $slug = User::generateUniqueSlug('@#$%^');

        expect($slug)->toBe('user-2');
    });
});

describe('Slug generation at registration', function () {
    it('generates a slug when registering via email', function () {
        $response = $this->post('/en/register', [
            'name' => 'Test User Registration',
            'email' => 'test-register@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $user = User::where('email', 'test-register@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->slug)->toBe('test-user-registration');
    });

    it('generates unique slugs for duplicate names at registration', function () {
        User::factory()->create([
            'name' => 'Duplicate Name',
            'email' => 'first@example.com',
            'slug' => 'duplicate-name',
        ]);

        $response = $this->post('/en/register', [
            'name' => 'Duplicate Name',
            'email' => 'second@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $user = User::where('email', 'second@example.com')->first();
        expect($user)->not->toBeNull();
        expect($user->slug)->toBe('duplicate-name-2');
    });
});
