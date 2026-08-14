<?php

use App\Models\User;

describe('User anonymization', function () {
    it('notAnonymized scope excludes anonymized users', function () {
        $active = User::factory()->create(['name' => 'Active User']);
        $anon = User::factory()->create(['name' => 'Anon User']);
        $anon->forceFill(['anonymized_at' => now()])->saveQuietly();

        $allIds = User::pluck('id');
        $scopedIds = User::notAnonymized()->pluck('id');

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

    it('loads anonymized users normally by default (no global scope)', function () {
        $anon = User::factory()->create();
        $anon->forceFill(['anonymized_at' => now()])->saveQuietly();

        // Without global scope, anonymized users load normally
        $found = User::find($anon->id);
        expect($found)->not->toBeNull()
            ->and($found->id)->toBe($anon->id)
            ->and($found->isAnonymized())->toBeTrue();
    });
});
