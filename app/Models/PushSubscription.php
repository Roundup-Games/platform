<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to subscriptions whose push service endpoint has expired (responded 410/404).
     *
     * Usage: Call scopeExpired on a chunked query to avoid flooding push services.
     */
    public function scopeExpired($query)
    {
        return $query->where('expired_at', '!=', null);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Check whether the push service endpoint is still valid by sending a HEAD request.
     * Returns true if the endpoint responded with 410 Gone or 404 Not Found.
     */
    public function isExpired(): bool
    {
        try {
            $response = Http::timeout(5)->head($this->endpoint);

            if ($response->status() === 410 || $response->status() === 404) {
                return true;
            }
        } catch (\Exception $e) {
            Log::warning('Push subscription expiry check failed', [
                'subscription_id' => $this->id,
                'endpoint' => $this->endpoint,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
