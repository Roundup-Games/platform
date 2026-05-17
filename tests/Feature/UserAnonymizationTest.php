<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

describe('User anonymization', function () {
    it('has anonymized_at column on users table', function () {
        expect(Schema::hasColumn('users', 'anonymized_at'))->toBeTrue();
    });

    it('excludes anonymized users by default via global scope', function () {
        $active = User::factory()->create(['name' => 'Active User']);
        $anon = User::factory()->create(['name' => 'Anon User']);
        $anon->forceFill(['anonymized_at' => now()])->saveQuietly();

        $allIds = User::withoutGlobalScope('not-anonymized')->pluck('id');
        $scopedIds = User::pluck('id');

        expect($allIds)->toContain($active->id, $anon->id)
            ->and($scopedIds)->toContain($active->id)
            ->and($scopedIds)->not->toContain($anon->id);
    });

    it('isAnonymized returns correct boolean', function () {
        $active = User::factory()->create();
        $anon = User::factory()->create();
        $anon->forceFill(['anonymized_at' => now()])->saveQuietly();

        expect($active->isAnonymized())->toBeFalse()
            ->and($anon->isAnonymized())->toBeTrue();
    });

    it('anonymize strips PII and sets anonymized_at', function () {
        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+1234567890',
            'bio' => 'A bio about Jane',
            'gender' => 'female',
            'pronouns' => 'she/her',
        ]);

        $userId = $user->id;
        $user->anonymize();

        // Reload without global scope to see the anonymized user
        $fresh = User::withoutGlobalScope('not-anonymized')->find($userId);

        expect($fresh->name)->toBe('Deleted User')
            ->and($fresh->email)->toBe('anon-' . $userId . '@anonymized.invalid')
            ->and($fresh->password)->toBeNull()
            ->and($fresh->phone)->toBeNull()
            ->and($fresh->bio)->toBeNull()
            ->and($fresh->avatar_url)->toBeNull()
            ->and($fresh->gender)->toBeNull()
            ->and($fresh->pronouns)->toBeNull()
            ->and($fresh->slug)->toBe('deleted-' . $userId)
            ->and($fresh->anonymized_at)->not->toBeNull()
            ->and($fresh->isAnonymized())->toBeTrue();
    });

    it('can load anonymized users with withoutGlobalScope', function () {
        $anon = User::factory()->create();
        $anon->forceFill(['anonymized_at' => now()])->saveQuietly();

        // Default scope should not find them
        expect(User::find($anon->id))->toBeNull();

        // Without scope should find them
        $found = User::withoutGlobalScope('not-anonymized')->find($anon->id);
        expect($found)->not->toBeNull()
            ->and($found->id)->toBe($anon->id);
    });
});
