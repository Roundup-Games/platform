<?php

namespace App\Models;

use App\Enums\DiscordCardStatus;
use Database\Factories\DiscordCardMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Tracks the Discord message id for a posted roundup Game card.
 *
 * Written by DiscordPublisher (T05) so re-publishes EDIT in place and
 * visibility-downgrades can DELETE the card.
 *
 * @property string $id
 * @property string $game_id roundup Game id
 * @property string $guild_id roundup DiscordGuild id
 * @property string $channel_id Discord channel snowflake
 * @property string|null $message_id Discord message snowflake (NULL while a pending card awaits approval)
 * @property DiscordCardStatus $status card lifecycle (posted default in v1)
 * @property string|null $moderator_user_id roundup User who moderated the card (NULL in v1)
 * @property Carbon|null $moderated_at when a moderator acted (NULL in v1)
 * @property Carbon|null $expires_at pending-window expiry (NULL in v1)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DiscordCardMessage extends Model
{
    /** @use HasFactory<DiscordCardMessageFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'game_id',
        'guild_id',
        'channel_id',
        'message_id',
        'status',
        'moderator_user_id',
        'moderated_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DiscordCardStatus::class,
            'moderated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $card) {
            if (empty($card->id)) {
                $card->id = (string) Str::orderedUuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /**
     * The roundup Game whose card was posted.
     *
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    /**
     * The roundup DiscordGuild the card was posted to.
     *
     * @return BelongsTo<DiscordGuild, $this>
     */
    public function guild(): BelongsTo
    {
        return $this->belongsTo(DiscordGuild::class, 'guild_id');
    }

    /**
     * The roundup User who moderated this card (NULL in v1; populated only by
     * the future Review-mode approval flow).
     *
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_user_id');
    }
}
