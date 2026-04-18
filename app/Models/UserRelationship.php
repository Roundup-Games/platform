<?php

namespace App\Models;

use App\Enums\RelationshipType;
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

        return static::firstOrCreate(
            [
                'user_id' => $initiator->id,
                'related_user_id' => $target->id,
                'type' => RelationshipType::Follow,
            ],
        );
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

        return static::where('user_id', $initiator->id)
            ->where('related_user_id', $target->id)
            ->where('type', RelationshipType::Follow)
            ->delete() > 0;
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

        return static::firstOrCreate(
            [
                'user_id' => $initiator->id,
                'related_user_id' => $target->id,
                'type' => RelationshipType::Block,
            ],
        );
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

        return static::where('user_id', $initiator->id)
            ->where('related_user_id', $target->id)
            ->where('type', RelationshipType::Block)
            ->delete() > 0;
    }
}
