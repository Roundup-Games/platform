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
     * Hash an email address with SHA-256 for storage.
     */
    public static function hashEmail(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
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
