<?php

namespace App\Models;

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
 * @property string $message_id Discord message snowflake
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DiscordCardMessage extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'game_id',
        'guild_id',
        'channel_id',
        'message_id',
    ];

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
}
