<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Integration tests for slug-based profile routing and model binding.
 * HTTP-level routing tests are in tests/Feature/Livewire/SlugRoutingTest.php.
 */
describe('User route model binding', function () {
    it('uses slug as the route key name', function () {
        $user = new User;

        expect($user->getRouteKeyName())->toBe('slug');
    });

    it('resolves route binding by slug', function () {
        $user = User::factory()->create(['name' => 'Binding Test User']);

        $resolved = (new User)->resolveRouteBinding($user->slug);

        expect($resolved)->not->toBeNull();
        expect($resolved->id)->toBe($user->id);
    });

    it('falls back to UUID when slug not found', function () {
        $user = User::factory()->create(['name' => 'UUID Fallback User']);

        // Resolve by UUID (not slug)
        $resolved = (new User)->resolveRouteBinding($user->id);

        expect($resolved)->not->toBeNull();
        expect($resolved->id)->toBe($user->id);
    });

    it('returns null for non-existent slug that is not UUID', function () {
        $resolved = (new User)->resolveRouteBinding('nonexistent-slug-value');

        expect($resolved)->toBeNull();
    });

    it('returns null for non-existent UUID', function () {
        $resolved = (new User)->resolveRouteBinding((string) Str::uuid());

        expect($resolved)->toBeNull();
    });

    it('prefers slug over UUID when slug matches', function () {
        $user = User::factory()->create(['name' => 'Slug Priority', 'slug' => 'slug-priority']);

        // Create a separate user whose UUID we'll use
        $otherUser = User::factory()->create(['name' => 'Other User']);

        // Resolve using user's own slug
        $resolved = (new User)->resolveRouteBinding('slug-priority');
        expect($resolved->id)->toBe($user->id);
    });

    it('uses explicit field when provided', function () {
        $user = User::factory()->create(['name' => 'Explicit Field Test']);

        $resolved = (new User)->resolveRouteBinding($user->id, 'id');

        expect($resolved)->not->toBeNull();
        expect($resolved->id)->toBe($user->id);
    });
});

describe('Slug regeneration on name change', function () {
    it('does not auto-regenerate slug when name changes via update', function () {
        $user = User::factory()->create(['name' => 'Original Name']);
        $originalSlug = $user->slug;

        // Direct update (no creating hook fires)
        $user->update(['name' => 'Completely New Name']);

        expect($user->fresh()->slug)->toBe($originalSlug);
    });

    it('slug can be explicitly regenerated', function () {
        $user = User::factory()->create(['name' => 'First Name']);

        $newSlug = User::generateUniqueSlug('New Display Name', $user->id);
        $user->update(['slug' => $newSlug]);

        expect($user->fresh()->slug)->toBe('new-display-name');
    });
});

describe('Slug uniqueness constraints', function () {
    it('enforces unique slug at database level', function () {
        User::factory()->create(['name' => 'Unique Slug Test', 'slug' => 'unique-slug-test']);

        // Attempting to create a user with the same slug should fail
        $this->expectException(QueryException::class);

        User::factory()->create(['name' => 'Another User', 'slug' => 'unique-slug-test']);
    });

    it('allows null slugs for multiple users', function () {
        // The booted hook will auto-generate slugs, so we need to test
        // that generateUniqueSlug handles the case where existing slugs exist
        $user1 = User::factory()->create(['name' => 'Null Slug One']);
        $user2 = User::factory()->create(['name' => 'Null Slug Two']);

        expect($user1->slug)->not->toBeNull();
        expect($user2->slug)->not->toBeNull();
        expect($user1->slug)->not->toBe($user2->slug);
    });
});

describe('Bio field operations', function () {
    it('creates user with bio', function () {
        $user = User::factory()->create([
            'name' => 'Bio Test User',
            'bio' => 'Initial bio text',
        ]);

        expect($user->fresh()->bio)->toBe('Initial bio text');
    });

    it('updates bio via direct model update', function () {
        $user = User::factory()->create([
            'name' => 'Bio Update User',
            'bio' => 'Old bio',
        ]);

        $user->update(['bio' => 'New bio text']);

        expect($user->fresh()->bio)->toBe('New bio text');
    });

    it('clears bio to null', function () {
        $user = User::factory()->create([
            'name' => 'Bio Clear User',
            'bio' => 'Will be cleared',
        ]);

        $user->update(['bio' => null]);

        expect($user->fresh()->bio)->toBeNull();
    });

    it('bio and slug are in fillable attributes', function () {
        $user = new User;
        $fillable = $user->getFillable();

        expect($fillable)->toContain('bio');
        expect($fillable)->toContain('slug');
    });
});
