<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Enums\RelationshipType;
use App\Jobs\UpdateUserDiscoveryCache;
use App\Notifications\NewFollower;
use App\Services\NotificationService;
use App\Services\PeopleDiscoveryService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $user_id
 * @property string $related_user_id
 * @property RelationshipType $type
 */
class UserRelationship extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'related_user_id', 'type'];

    protected function casts(): array
    {
        return [
            'type' => RelationshipType::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $rel) {
            if (empty($rel->id)) {
                $rel->id = (string) Str::orderedUuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function related(): BelongsTo
    {
        return $this->belongsTo(User::class, 'related_user_id');
    }

    // ── Action Methods ─────────────────────────────────

    /**
     * Create a follow relationship from $initiator to $target.
     * Silently no-ops if already following.
     *
     * @param  bool  $notify  When true (default), dispatches the NewFollower
     *                        notification to the target on first-time follows.
     *                        Pass false for implicit/system-initiated follows
     *                        (e.g. auto-follow-on-game-join) so popular hosts
     *                        are not spammed with notifications for actions
     *                        the player did not explicitly take.
     */
    public static function follow(User $initiator, User $target, bool $notify = true): self
    {
        $initiatorId = (string) $initiator->id;
        $targetId = (string) $target->id;

        Log::info('user.relationship.follow', [
            'user_id' => $initiatorId,
            'target_id' => $targetId,
            'action' => 'follow',
            'notify' => $notify,
        ]);

        $rel = static::firstOrCreate(
            [
                'user_id' => $initiatorId,
                'related_user_id' => $targetId,
                'type' => RelationshipType::Follow,
            ],
        );

        // Invalidate discovery caches — initiator's candidate pool changed
        PeopleDiscoveryService::invalidateCacheFor($initiatorId);
        UpdateUserDiscoveryCache::dispatch($initiatorId, 'follow');

        // Dashboard feed cache invalidation is handled by UserRelationshipObserver

        // Dispatch NewFollower notification to the target user, unless the
        // caller opted out (system-initiated follows that should not ping).
        if ($notify && $rel->wasRecentlyCreated) {
            try {
                app(NotificationService::class)->send(
                    $target,
                    new NewFollower($initiator),
                    NotificationCategory::NewFollower,
                );
            } catch (\Throwable $e) {
                Log::error('notification.follow_dispatch_failed', [
                    'initiator_id' => $initiatorId,
                    'target_id' => $targetId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $rel;
    }

    /**
     * Remove a follow relationship from $initiator to $target.
     * Returns true if a record was deleted, false otherwise.
     */
    public static function unfollow(User $initiator, User $target): bool
    {
        $initiatorId = (string) $initiator->id;
        $targetId = (string) $target->id;

        Log::info('user.relationship.unfollow', [
            'user_id' => $initiatorId,
            'target_id' => $targetId,
            'action' => 'unfollow',
        ]);

        $rel = static::where('user_id', $initiatorId)
            ->where('related_user_id', $targetId)
            ->where('type', RelationshipType::Follow)
            ->first();

        if (! $rel) {
            return false;
        }

        $rel->delete();

        PeopleDiscoveryService::invalidateCacheFor($initiatorId);
        UpdateUserDiscoveryCache::dispatch($initiatorId, 'unfollow');

        // Dashboard feed cache invalidation is handled by UserRelationshipObserver (deleted event)

        return true;
    }

    /**
     * Block $target from $initiator's perspective.
     * Side effect: removes any existing follow relationships in both directions.
     */
    public static function block(User $initiator, User $target): self
    {
        $initiatorId = (string) $initiator->id;
        $targetId = (string) $target->id;

        // Remove follows in both directions as a side effect of blocking
        static::where('user_id', $initiatorId)
            ->where('related_user_id', $targetId)
            ->where('type', RelationshipType::Follow)
            ->delete();

        static::where('user_id', $targetId)
            ->where('related_user_id', $initiatorId)
            ->where('type', RelationshipType::Follow)
            ->delete();

        Log::info('user.relationship.block', [
            'user_id' => $initiatorId,
            'target_id' => $targetId,
            'action' => 'block',
        ]);

        $rel = static::firstOrCreate(
            [
                'user_id' => $initiatorId,
                'related_user_id' => $targetId,
                'type' => RelationshipType::Block,
            ],
        );

        // Invalidate discovery caches for both users
        PeopleDiscoveryService::invalidateCacheFor($initiatorId);
        PeopleDiscoveryService::invalidateCacheFor($targetId);
        UpdateUserDiscoveryCache::dispatch($initiatorId, 'block');
        UpdateUserDiscoveryCache::dispatch($targetId, 'block');

        return $rel;
    }

    /**
     * Remove a block from $initiator on $target.
     * Returns true if a record was deleted, false otherwise.
     */
    public static function unblock(User $initiator, User $target): bool
    {
        $initiatorId = (string) $initiator->id;
        $targetId = (string) $target->id;

        Log::info('user.relationship.unblock', [
            'user_id' => $initiatorId,
            'target_id' => $targetId,
            'action' => 'unblock',
        ]);

        $deleted = static::where('user_id', $initiatorId)
            ->where('related_user_id', $targetId)
            ->where('type', RelationshipType::Block)
            ->delete() > 0;

        if ($deleted) {
            // Both users' candidate pools change after unblock
            PeopleDiscoveryService::invalidateCacheFor($initiatorId);
            PeopleDiscoveryService::invalidateCacheFor($targetId);
            UpdateUserDiscoveryCache::dispatch($initiatorId, 'unblock');
            UpdateUserDiscoveryCache::dispatch($targetId, 'unblock');
        }

        return $deleted;
    }
}
