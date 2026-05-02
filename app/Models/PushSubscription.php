<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Minishlink\WebPush\Subscription;

class PushSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'endpoint',
        'p256h_key',
        'auth_token',
        'user_agent',
    ];

    protected $hidden = [
        'p256h_key',
        'auth_token',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to subscriptions belonging to a specific user.
     */
    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Convert to a Minishlink WebPush Subscription instance.
     *
     * Maps the project convention 'p256h_key' DB column to the
     * standard Web Push 'p256dh' key name expected by the library.
     */
    public function toWebPushSubscription(): Subscription
    {
        return Subscription::create([
            'endpoint' => $this->endpoint,
            'keys' => [
                'p256dh' => $this->p256h_key,
                'auth' => $this->auth_token,
            ],
        ]);
    }
}
