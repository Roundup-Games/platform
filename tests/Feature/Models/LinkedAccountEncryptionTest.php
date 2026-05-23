<?php

use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

describe('LinkedAccount encryption', function () {
    it('encrypts token in the database', function () {
        $user = User::factory()->create();

        $account = LinkedAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'enc-test-1',
            'token' => 'plaintext-access-token',
            'refresh_token' => 'plaintext-refresh-token',
        ]);

        // Model accessor decrypts automatically
        expect($account->token)->toBe('plaintext-access-token');
        expect($account->refresh_token)->toBe('plaintext-refresh-token');

        // Raw DB value should NOT be plaintext
        $raw = \DB::table('linked_accounts')->where('id', $account->id)->first();
        expect($raw->token)->not->toBe('plaintext-access-token');
        expect($raw->refresh_token)->not->toBe('plaintext-refresh-token');

        // Raw value should be decryptable back to original
        expect(Crypt::decryptString($raw->token))->toBe('plaintext-access-token');
        expect(Crypt::decryptString($raw->refresh_token))->toBe('plaintext-refresh-token');
    });

    it('handles null tokens gracefully', function () {
        $user = User::factory()->create();

        $account = LinkedAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'enc-test-2',
            'token' => 'some-token',
            'refresh_token' => null,
        ]);

        expect($account->refresh_token)->toBeNull();

        $raw = \DB::table('linked_accounts')->where('id', $account->id)->first();
        expect($raw->refresh_token)->toBeNull();
    });

    it('round-trips token through encrypt-then-decrypt', function () {
        $user = User::factory()->create();

        $account = LinkedAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'enc-test-3',
            'token' => 'original-token',
        ]);

        // Update token
        $account->update(['token' => 'updated-token']);
        $account->refresh();

        expect($account->token)->toBe('updated-token');
    });
});
