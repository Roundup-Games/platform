<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One MCP agent credential (M079/S01/T02, D277): the consumer identity an
 * mcp_calls audit row points at.
 *
 * The linked Sanctum personal access token carries the abilities
 * (mcp:read/write/host/ops, checked via tokenCan at dispatch); this row
 * carries the issuing relationship and the lifecycle:
 *
 *  - revoked_at set  → the token (and thus the agent) is dead at the
 *    middleware layer, even before/if the PAT row is deleted.
 *  - PAT deleted     → nullOnDelete nulls personal_access_token_id; the
 *    client row remains as audit history of the revoked consumer.
 */
class McpClient extends Model
{
    use HasPlatformUuid;

    /** T03 keys the per-consumer rate limiter on this tier. */
    public const string RATE_LIMIT_TIER_STANDARD = 'standard';

    protected $fillable = [
        'name',
        'rate_limit_tier',
        'personal_access_token_id',
        'revoked_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The Sanctum token that authenticates this consumer. Null after the
     * token row is deleted on revocation (history survives).
     *
     * @return BelongsTo<PersonalAccessToken, $this>
     */
    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class);
    }

    /**
     * @return HasMany<McpCall, $this>
     */
    public function calls(): HasMany
    {
        return $this->hasMany(McpCall::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Revoke the consumer: stamp revoked_at and delete the linked token in
     * one transaction. Deleting the token makes Sanctum unable to resolve
     * the bearer credential at all; the revoked_at stamp additionally
     * fails closed at the middleware layer for the window where the token
     * row still exists (or if linkage is ever rebuilt).
     */
    public function revoke(): void
    {
        if ($this->isRevoked()) {
            return;
        }

        DB::transaction(function (): void {
            $this->forceFill(['revoked_at' => now()])->save();

            if ($this->personalAccessToken()->exists()) {
                $this->personalAccessToken()->delete();
            }
        });
    }

    /**
     * Consumers that may still authenticate (token linkage intact, not revoked).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
