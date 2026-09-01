<?php

namespace App\Models;

use App\Jobs\PublishGameBulletinToDiscord;
use Database\Factories\DiscordBulletinMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Tracks the teaser message the bot posted into a per-session Discord thread
 * for a GameBulletin (M062/S01).
 *
 * One row per (bulletin, thread) — the unique index is the idempotency gate
 * {@see PublishGameBulletinToDiscord} checks before posting, so a
 * queue retry after partial success never double-posts a thread. A terminal
 * failure (non-retryable 4xx) is recorded as STATUS_FAILED with the Discord
 * status in error_code, which also short-circuits retries for that thread.
 *
 * Only teaser content is ever posted to these public threads (D132); the full
 * bulletin body travels exclusively through participant notification
 * channels.
 *
 * @property string $id
 * @property string $bulletin_id roundup GameBulletin id
 * @property string $guild_id roundup DiscordGuild id
 * @property string $thread_id Discord thread channel snowflake the teaser was posted into
 * @property string|null $message_id Discord bot message snowflake (NULL on a failed post)
 * @property string $status self::STATUS_* lifecycle
 * @property int|null $error_code terminal Discord HTTP status for STATUS_FAILED rows
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DiscordBulletinMessage extends Model
{
    /** @use HasFactory<DiscordBulletinMessageFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /** Teaser posted successfully; message_id is set. */
    public const STATUS_POSTED = 'posted';

    /** Terminal failure (non-retryable 4xx); error_code carries the status. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'bulletin_id',
        'guild_id',
        'thread_id',
        'message_id',
        'status',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'error_code' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $message) {
            if (empty($message->id)) {
                $message->id = (string) Str::orderedUuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /**
     * The GameBulletin whose teaser was posted.
     *
     * @return BelongsTo<GameBulletin, $this>
     */
    public function bulletin(): BelongsTo
    {
        return $this->belongsTo(GameBulletin::class);
    }

    /**
     * The roundup DiscordGuild whose session thread received the teaser.
     *
     * @return BelongsTo<DiscordGuild, $this>
     */
    public function guild(): BelongsTo
    {
        return $this->belongsTo(DiscordGuild::class);
    }
}
