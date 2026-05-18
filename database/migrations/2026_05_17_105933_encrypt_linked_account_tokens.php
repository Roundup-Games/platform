<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypt existing plaintext OAuth tokens in linked_accounts.
 *
 * The LinkedAccount model now uses Laravel's 'encrypted' cast on token
 * and refresh_token columns. Existing plaintext values must be encrypted
 * before the cast is active, or DecryptException will be thrown on read.
 *
 * This migration:
 * 1. Reads all existing token/refresh_token values from the DB
 * 2. Encrypts any that are not already encrypted ( skips null/empty )
 * 3. Writes them back so the encrypted cast can decrypt them normally
 *
 * Graceful: detects already-encrypted values (base64-encoded JSON with
 * "iv" key) and skips them, making this migration idempotent and safe
 * to re-run.
 */
return new class extends Migration
{
    /**
     * Detect whether a string looks like a Laravel encrypted value.
     *
     * Laravel's encrypt() produces base64-encoded JSON with an "iv" key.
     */
    private function isEncrypted(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true; // null/empty doesn't need encryption
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        $json = json_decode($decoded, true);

        return is_array($json) && isset($json['iv']);
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('linked_accounts')
            ->select('id', 'token', 'refresh_token')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $updates = [];

                    if (! $this->isEncrypted($row->token)) {
                        $updates['token'] = Crypt::encryptString($row->token);
                    }

                    if (! $this->isEncrypted($row->refresh_token)) {
                        $updates['refresh_token'] = $row->refresh_token !== null
                            ? Crypt::encryptString($row->refresh_token)
                            : null;
                    }

                    if (! empty($updates)) {
                        DB::table('linked_accounts')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     *
     * Decrypt tokens back to plaintext. Note: this is intentionally
     * one-way in practice — down() is provided for completeness but
     * decrypting credentials to plaintext is not recommended in production.
     */
    public function down(): void
    {
        DB::table('linked_accounts')
            ->select('id', 'token', 'refresh_token')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $updates = [];

                    if ($this->isEncrypted($row->token)) {
                        $updates['token'] = Crypt::decryptString($row->token);
                    }

                    if ($row->refresh_token !== null && $this->isEncrypted($row->refresh_token)) {
                        $updates['refresh_token'] = Crypt::decryptString($row->refresh_token);
                    }

                    if (! empty($updates)) {
                        DB::table('linked_accounts')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }
};
