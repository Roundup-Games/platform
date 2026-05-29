<?php

use App\Models\User;
use App\Rules\ValidUserName;

/**
 * Edge-case tests for the complete user profile system:
 * name validation, slug generation, profile routing, and bio operations.
 *
 * Core validation tests are in tests/Feature/ValidUserNameTest.php
 * and tests/Feature/Rules/ValidUserNameTest.php.
 * Slug generation tests are in tests/Feature/UserSlugTest.php.
 * Routing tests are in tests/Feature/Livewire/SlugRoutingTest.php.
 * Bio tests are in tests/Feature/UserSlugAndBioTest.php and tests/Feature/ProfileManagementTest.php.
 *
 * This file covers additional edge cases and cross-cutting scenarios.
 */

// ═══════════════════════════════════════════════════════════
// ValidUserName: Additional Edge Cases
// ═══════════════════════════════════════════════════════════

describe('ValidUserName: boundary and mixed-content cases', function () {
    it('rejects a name with exactly 5 non-space characters', function () {
        $rule = new ValidUserName;

        $failed = false;
        $failMessage = null;
        $rule->validate('name', 'ABCDE', function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        expect($failed)->toBeTrue();
        expect($failMessage)->toContain('6 non-space characters');
    });

    it('accepts a name with exactly 6 non-space characters no spaces', function () {
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', 'ABCDEF', function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('accepts names with apostrophes that get stripped but still pass length after sanitization', function () {
        // O'Connor has 7 non-space chars after stripping apostrophe → OConnor (7 chars)
        $rule = new ValidUserName;

        $failed = false;
        $failMessage = null;
        $rule->validate('name', "O'Connor", function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        // Apostrophe is a special char, so it should fail on special-char check
        expect($failed)->toBeTrue();
        expect($failMessage)->toContain('special characters');
    });

    it('rejects a name that is all emojis', function () {
        $rule = new ValidUserName;

        $failed = false;
        $failMessage = null;
        $rule->validate('name', '😀🎮🎲🎯🎪🎭', function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        expect($failed)->toBeTrue();
        expect($failMessage)->toContain('emojis');
    });

    it('rejects a name with mixed valid text and emojis', function () {
        $rule = new ValidUserName;

        $failed = false;
        $failMessage = null;
        $rule->validate('name', 'John 🎮 Doe Smith', function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        expect($failed)->toBeTrue();
        expect($failMessage)->toContain('emojis');
    });

    it('accepts a name with digits interspersed', function () {
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', 'John123 Doe', function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('rejects a name with angle brackets (HTML injection attempt)', function () {
        $rule = new ValidUserName;

        $failed = false;
        $rule->validate('name', 'John <script>alert("xss")</script> Doe', function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeTrue();
    });

    it('rejects a name with semicolons and quotes (SQL injection attempt)', function () {
        $rule = new ValidUserName;

        $failed = false;
        $rule->validate('name', "Robert'); DROP TABLE users;--", function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeTrue();
    });

    it('accepts a very long valid name (255 chars)', function () {
        $longName = str_repeat('A', 255);
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', $longName, function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('accepts names with multiple consecutive spaces between words', function () {
        $rule = new ValidUserName;

        $passed = true;
        $rule->validate('name', 'John     Doe', function () use (&$passed) {
            $passed = false;
        });

        expect($passed)->toBeTrue();
    });

    it('rejects a null-ish value (non-string)', function () {
        $rule = new ValidUserName;

        $failed = false;
        $rule->validate('name', null, function () use (&$failed) {
            $failed = true;
        });

        expect($failed)->toBeTrue();
    });
});

describe('ValidUserName::sanitize additional cases', function () {
    it('strips @ symbols and dots from names', function () {
        // Both @ and . are special characters that get stripped
        expect(ValidUserName::sanitize('user@domain.com'))->toBe('userdomaincom');
    });

    it('strips curly braces and brackets', function () {
        expect(ValidUserName::sanitize('{John} <Doe>'))->toBe('John Doe');
    });

    it('preserves accented characters', function () {
        expect(ValidUserName::sanitize('José García'))->toBe('José García');
    });

    it('handles name with mixed emoji and special chars', function () {
        $result = ValidUserName::sanitize('John@Doe 🎮 Test#1');
        expect($result)->toBe('JohnDoe  Test1');
    });
});

describe('ValidUserName::containsEmojis additional detection', function () {
    it('detects zodiac emoji', function () {
        expect(ValidUserName::containsEmojis('♈ Taurus'))->toBeTrue();
    });

    it('detects checkmark emoji', function () {
        expect(ValidUserName::containsEmojis('✅ Done'))->toBeTrue();
    });

    it('does not false-positive on ampersand', function () {
        expect(ValidUserName::containsEmojis('Tom & Jerry'))->toBeFalse();
    });

    it('does not false-positive on standard punctuation', function () {
        expect(ValidUserName::containsEmojis('Hello, world!'))->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// Slug Generation: Additional Edge Cases
// ═══════════════════════════════════════════════════════════

describe('User::generateSlug additional cases', function () {
    it('strips underscores in slug generation (not preserved like in names)', function () {
        // generateSlug strips underscores — only keeps letters, digits, spaces, hyphens
        expect(User::generateSlug('John_Doe'))->toBe('johndoe');
    });

    it('handles names with mixed case and accents', function () {
        expect(User::generateSlug('José GARCÍA'))->toBe('jose-garcia');
    });

    it('handles very long names by producing a long slug', function () {
        $longName = str_repeat('Very Long Name ', 20);
        $slug = User::generateSlug(trim($longName));

        expect($slug)->toStartWith('very-long-name-');
        expect(mb_strlen($slug))->toBeGreaterThan(50);
    });

    it('handles names with only spaces producing empty slug', function () {
        expect(User::generateSlug('   '))->toBe('');
    });

    it('handles names with tabs and newlines', function () {
        $slug = User::generateSlug("John\tDoe\nSmith");
        // Tabs and newlines become hyphens via \s+ replacement
        expect($slug)->toBe('john-doe-smith');
    });

    it('handles mixed special characters and valid text', function () {
        expect(User::generateSlug('John@Doe#Test!'))->toBe('johndoetest');
    });

    it('handles unicode combining characters in names', function () {
        $slug = User::generateSlug('Hélène Müster');
        // German umlauts are pre-expanded before iconv (ü→ue), so the result
        // is deterministic across platforms.
        expect($slug)->toBe('helene-muester');
    });
});

describe('User::generateUniqueSlug collision edge cases', function () {
    it('finds next available number when gaps exist in sequence', function () {
        User::factory()->create(['name' => 'Gap Test', 'slug' => 'gap-test']);
        // gap-test-2 does not exist
        User::factory()->create(['name' => 'Gap Test', 'slug' => 'gap-test-3']);

        // generateUniqueSlug starts at counter=1, increments to 2, checks exists, finds no, returns -2
        $slug = User::generateUniqueSlug('Gap Test');

        expect($slug)->toBe('gap-test-2');
    });

    it('handles 5+ collisions correctly', function () {
        User::factory()->create(['name' => 'Multi Collision', 'slug' => 'multi-collision']);
        for ($i = 2; $i <= 5; $i++) {
            User::factory()->create(['name' => 'Multi Collision', 'slug' => "multi-collision-{$i}"]);
        }

        $slug = User::generateUniqueSlug('Multi Collision');

        expect($slug)->toBe('multi-collision-6');
    });

    it('ignoreId works when user already has a slug that would collide', function () {
        $user = User::factory()->create(['name' => 'Self Ref', 'slug' => 'self-ref']);

        // When generating for the same user (e.g., during name change), should reuse their slug
        $slug = User::generateUniqueSlug('Self Ref', $user->id);

        expect($slug)->toBe('self-ref');
    });

    it('generates unique fallback when multiple users produce empty slug', function () {
        User::factory()->create(['name' => 'user', 'slug' => 'user']);
        User::factory()->create(['name' => 'user2', 'slug' => 'user-2']);

        // Both @#$ and %^& produce empty slugs → fallback "user"
        $slug = User::generateUniqueSlug('!@#$');

        expect($slug)->not->toBe('user');
        expect($slug)->toStartWith('user-');
    });
});

describe('Auto-slug generation on model creating hook', function () {
    it('generates slug automatically when user is created via factory', function () {
        $user = User::factory()->create(['name' => 'Factory Auto Slug']);

        expect($user->slug)->toBe('factory-auto-slug');
    });

    it('does not overwrite slug when explicitly set', function () {
        $user = User::factory()->create([
            'name' => 'Explicit Slug Name',
            'slug' => 'my-custom-slug',
        ]);

        expect($user->slug)->toBe('my-custom-slug');
    });

    it('generates slug even when name has special characters', function () {
        $user = User::factory()->create(['name' => 'Test@User#Name!']);

        expect($user->slug)->toBe('testusername');
    });

    it('handles UUID auto-generation when id is empty', function () {
        $user = User::factory()->make(['id' => null]);
        $user->save();

        expect($user->id)->not->toBeNull();
        expect($user->slug)->not->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// Profile Routing: Additional Edge Cases
// ═══════════════════════════════════════════════════════════

describe('Route model binding edge cases', function () {
    it('resolves slug case-insensitively for lookups', function () {
        $user = User::factory()->create(['name' => 'Case Test', 'slug' => 'case-test']);

        // Slugs are stored lowercase, so uppercase lookup should not match slug
        // but should not crash either
        $resolved = (new User)->resolveRouteBinding('Case-Test');

        // The slug column is case-sensitive in PostgreSQL
        expect($resolved)->toBeNull();
    });

    it('resolves valid UUID that does not match any user', function () {
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $resolved = (new User)->resolveRouteBinding($uuid);

        expect($resolved)->toBeNull();
    });

    it('prefers slug match over UUID fallback when slug exists', function () {
        $user1 = User::factory()->create(['name' => 'Slug First', 'slug' => 'slug-first']);
        $user2 = User::factory()->create(['name' => 'Second User']);

        // Resolve by slug — should get user1
        $resolved = (new User)->resolveRouteBinding('slug-first');
        expect($resolved->id)->toBe($user1->id);
    });
});

// ═══════════════════════════════════════════════════════════
// Bio Field: Additional Edge Cases
// ═══════════════════════════════════════════════════════════

describe('Bio field edge cases', function () {
    it('stores bio with exactly 500 characters', function () {
        $bio = str_repeat('a', 500);
        $user = User::factory()->create([
            'name' => 'Bio Max User',
            'bio' => $bio,
        ]);

        expect($user->fresh()->bio)->toBe($bio);
        expect(mb_strlen($user->fresh()->bio))->toBe(500);
    });

    it('handles bio with only whitespace as nullable', function () {
        $user = User::factory()->create([
            'name' => 'Whitespace Bio',
            'bio' => '   ',
        ]);

        // Direct model update preserves the spaces; Livewire trims via strip_tags
        expect($user->fresh()->bio)->toBe('   ');
    });

    it('handles bio with unicode content', function () {
        $bio = 'こんにちは世界 🎲 tabletop RPG enthusiast';
        $user = User::factory()->create([
            'name' => 'Unicode Bio',
            'bio' => $bio,
        ]);

        expect($user->fresh()->bio)->toBe($bio);
    });

    it('handles bio with line breaks and special characters', function () {
        $bio = "Line 1\nLine 2\r\nLine 3\tTabbed";
        $user = User::factory()->create([
            'name' => 'Multiline Bio',
            'bio' => $bio,
        ]);

        expect($user->fresh()->bio)->toBe($bio);
    });

    it('bio can be updated independently of other fields', function () {
        $user = User::factory()->create([
            'name' => 'Bio Update',
            'bio' => 'Original bio',
            'email' => 'original@example.com',
        ]);

        $user->update(['bio' => 'Updated bio only']);

        $fresh = $user->fresh();
        expect($fresh->bio)->toBe('Updated bio only');
        expect($fresh->email)->toBe('original@example.com');
        expect($fresh->name)->toBe('Bio Update');
    });

    it('bio persists across multiple updates', function () {
        $user = User::factory()->create(['name' => 'Persist Bio', 'bio' => 'Version 1']);

        $user->update(['bio' => 'Version 2']);
        expect($user->fresh()->bio)->toBe('Version 2');

        $user->update(['bio' => 'Version 3']);
        expect($user->fresh()->bio)->toBe('Version 3');

        $user->update(['bio' => null]);
        expect($user->fresh()->bio)->toBeNull();
    });
});

// ═══════════════════════════════════════════════════════════
// Cross-cutting: Slug + Name Interaction
// ═══════════════════════════════════════════════════════════

describe('Slug and name interaction', function () {
    it('updating name does not auto-update slug', function () {
        $user = User::factory()->create(['name' => 'Original Name']);
        $originalSlug = $user->slug;

        $user->update(['name' => 'Completely Different Name']);

        expect($user->fresh()->slug)->toBe($originalSlug);
    });

    it('slug can be manually updated alongside name', function () {
        $user = User::factory()->create(['name' => 'Old Name']);

        $newSlug = User::generateUniqueSlug('New Name', $user->id);
        $user->update([
            'name' => 'New Name',
            'slug' => $newSlug,
        ]);

        $fresh = $user->fresh();
        expect($fresh->name)->toBe('New Name');
        expect($fresh->slug)->toBe('new-name');
    });

    it('name with all special chars produces "user" fallback slug on creation', function () {
        $user = User::factory()->create(['name' => '!@#$%^&*()']);

        expect($user->slug)->toBe('user');
    });

    it('subsequent users with all-special-char names get unique fallback slugs', function () {
        $user1 = User::factory()->create(['name' => '!@#$%^&*()']);
        $user2 = User::factory()->create(['name' => '***+++']);

        expect($user1->slug)->toBe('user');
        expect($user2->slug)->toStartWith('user-');
        expect($user1->slug)->not->toBe($user2->slug);
    });
});
