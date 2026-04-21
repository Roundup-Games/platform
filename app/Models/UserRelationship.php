<?php

namespace App\Models;

use App\Enums\RelationshipType;
use App\Jobs\UpdateUserDiscoveryCache;
use App\Services\PeopleDiscoveryService;
use Database\Factories\UserRelationshipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class UserRelationship extends Model
{
    use HasFactory;
    protected $fillable = ['user_id', 'related_user_id', 'type'];

    protected function casts(): array
    {
        return [
            'type' => RelationshipType::class,
        ];
    }

    // ── Relationships ──────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(User::class, 'related_user_id');
    }

    // ── Action Methods ─────────────────────────────────

    /**
     * Create a follow relationship from $initiator to $target.
     * Silently no-ops if already following.
     */
    public static function follow(User $initiator, User $target): self
    {
        Log::info('user.relationship.follow', [
            'user_id' => $initiator->id,
            'target_id' => $target->id,
            'action' => 'follow',
        ]);

        $rel = static::firstOrCreate(
            [
                'user_id' => $initiator->id,
                'related_user_id' => $target->id,
                'type' => RelationshipType::Follow,
            ],
        );

        // Invalidate discovery caches — initiator's candidate pool changed
        PeopleDiscoveryService::invalidateCacheFor($initiator->id);
        UpdateUserDiscoveryCache::dispatch($initiator->id, 'follow');

        return $rel;
    }

    /**
     * Remove a follow relationship from $initiator to $target.
     * Returns true if a record was deleted, false otherwise.
     */
    public static function unfollow(User $initiator, User $target): bool
    {
        Log::info('user.relationship.unfollow', [
            'user_id' => $initiator->id,
            'target_id' => $target->id,
            'action' => 'unfollow',
        ]);

        $deleted = static::where('user_id', $initiator->id)
            ->where('related_user_id', $target->id)
            ->where('type', RelationshipType::Follow)
            ->delete() > 0;

        if ($deleted) {
            PeopleDiscoveryService::invalidateCacheFor($initiator->id);
            UpdateUserDiscoveryCache::dispatch($initiator->id, 'unfollow');
        }

        return $deleted;
    }

    /**
     * Block $target from $initiator's perspective.
     * Side effect: removes any existing follow relationships in both directions.
     */
    public static function block(User $initiator, User $target): self
    {
        // Remove follows in both directions as a side effect of blocking
        static::where('user_id', $initiator->id)
            ->where('related_user_id', $target->id)
            ->where('type', RelationshipType::Follow)
            ->delete();

        static::where('user_id', $target->id)
            ->where('related_user_id', $initiator->id)
            ->where('type', RelationshipType::Follow)
            ->delete();

        Log::info('user.relationship.block', [
            'user_id' => $initiator->id,
            'target_id' => $target->id,
            'action' => 'block',
        ]);

        $rel = static::firstOrCreate(
            [
                'user_id' => $initiator->id,
                'related_user_id' => $target->id,
                'type' => RelationshipType::Block,
            ],
        );

        // Invalidate discovery caches for both users
        PeopleDiscoveryService::invalidateCacheFor($initiator->id);
        PeopleDiscoveryService::invalidateCacheFor($target->id);
        UpdateUserDiscoveryCache::dispatch($initiator->id, 'block');
        UpdateUserDiscoveryCache::dispatch($target->id, 'block');

        return $rel;
    }

    /**
     * Remove a block from $initiator on $target.
     * Returns true if a record was deleted, false otherwise.
     */
    public static function unblock(User $initiator, User $target): bool
    {
        Log::info('user.relationship.unblock', [
            'user_id' => $initiator->id,
            'target_id' => $target->id,
            'action' => 'unblock',
        ]);

        $deleted = static::where('user_id', $initiator->id)
            ->where('related_user_id', $target->id)
            ->where('type', RelationshipType::Block)
            ->delete() > 0;

        if ($deleted) {
            // Both users' candidate pools change after unblock
            PeopleDiscoveryService::invalidateCacheFor($initiator->id);
            PeopleDiscoveryService::invalidateCacheFor($target->id);
            UpdateUserDiscoveryCache::dispatch($initiator->id, 'unblock');
            UpdateUserDiscoveryCache::dispatch($target->id, 'unblock');
        }

        return $deleted;
    }
}
