<?php

namespace App\Models;

use App\Jobs\PublishGameBulletinToDiscord;
use App\Models\Concerns\HasPlatformUuid;
use Database\Factories\GameBulletinFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $content
 * @property bool $is_pinned
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

    use HasPlatformUuid;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'game_id',
        'user_id',
        'content',
        'expires_at',
        'is_pinned',
    ];

    /**
     * Create a bulletin as a specific application-chosen author.
     *
     * Use this in application code (services, jobs) to make the author
     * explicit application state — never request input. postBy is the
     * canonical application write path: services (the one-shot Epilogue
     * bulletin posted on completion), jobs, and the host-facing board
     * all create through it.
     */
    public static function postBy(string $gameId, string $userId, string $content, ?string $expiresAt = null, bool $isPinned = false): self
    {
        return static::create([
            'game_id' => $gameId,
            'user_id' => $userId,
            'content' => $content,
            'expires_at' => $expiresAt,
            'is_pinned' => $isPinned,
        ]);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_pinned' => 'boolean',
        ];
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

    /**
     * The teaser messages pushed into per-session Discord threads for this
     * bulletin (M062/S01) — one row per guild thread, the retry-idempotency
     * gate for {@see PublishGameBulletinToDiscord}.
     *
     * @return HasMany<DiscordBulletinMessage, $this>
     */
    public function discordBulletinMessages(): HasMany
    {
        // Explicit FK: Laravel derives "game_bulletin_id" from the related
        // model's basename ("DiscordBulletin" + _id) — same consecutive-caps
        // naming trap as GMProfile (see KNOWLEDGE.md).
        return $this->hasMany(DiscordBulletinMessage::class, 'bulletin_id');
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
