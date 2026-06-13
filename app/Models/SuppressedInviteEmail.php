<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuppressedInviteEmail extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email_hash',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Hash an email address using HMAC-SHA256 with the application key.
     *
     * Uses HMAC rather than plain SHA-256 so that the hashes cannot be
     * reversed via rainbow-table or brute-force lookup without the app key.
     * The app key is secret and unique per environment, making offline
     * attacks infeasible even if the database is compromised.
     */
    public static function hashEmail(string $email): string
    {
        $key = config('app.key');

        return hash_hmac('sha256', strtolower(trim($email)), is_string($key) ? $key : '');
    }

    /**
     * Check whether the given email is suppressed.
     */
    public static function isSuppressed(string $email): bool
    {
        return static::where('email_hash', static::hashEmail($email))->exists();
    }

    /**
     * Suppress an email address. Ignores duplicates silently.
     */
    public static function suppress(string $email): void
    {
        static::firstOrCreate(
            ['email_hash' => static::hashEmail($email)],
            ['created_at' => now()],
        );
    }
}
