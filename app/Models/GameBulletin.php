<?php

namespace App\Models;

use Database\Factories\GameBulletinFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $content
 * @property string $game_id
 * @property int $user_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GameBulletin extends Model
{
    /** @use HasFactory<GameBulletinFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'game_id',
        'user_id',
        'content',
        'expires_at',
    ];

    /**
     * Create a bulletin as a specific host user.
     *
     * Use this in application code to make the intent explicit —
     * the author is always the authenticated host, not request input.
     * The factory can use ::create() directly since it's test-only.
     */
    public static function postAsHost(string $gameId, string $userId, string $content, ?string $expiresAt = null): self
    {
        return static::create([
            'game_id' => $gameId,
            'user_id' => $userId,
            'content' => $content,
            'expires_at' => $expiresAt,
        ]);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $bulletin) {
            if (empty($bulletin->id)) {
                $bulletin->id = (string) Str::uuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ─────────────────────────────────────────

    /**
     * Scope to bulletins that have not expired.
     * A bulletin without an expires_at is considered never-expiring.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotExpired(Builder $query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    // ── Accessors ──────────────────────────────────────

    /**
     * Whether this bulletin has expired.
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
