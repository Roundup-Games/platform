<?php

namespace App\Models;

use App\Enums\DiscordModerationMode;
use Database\Factories\DiscordGuildFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One row per Discord guild ("server") that has installed the roundup bot.
 *
 * @property string $id
 * @property string $guild_id Discord guild snowflake
 * @property string $name
 * @property string|null $icon
 * @property string $owner_user_id roundup User who installed the bot
 * @property string|null $calendar_channel_id
 * @property string|null $digest_message_id current digest's Discord message snowflake (M057/S02)
 * @property string|null $digest_channel_id channel the current digest was posted to (reconfig detection)
 * @property string|null $games_channel_id
 * @property string|null $locale
 * @property bool $paused
 * @property DiscordModerationMode $moderation_mode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DiscordGuild extends Model
{
    /** @use HasFactory<DiscordGuildFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'guild_id',
        'name',
        'icon',
        'owner_user_id',
        'calendar_channel_id',
        'digest_message_id',
        'digest_channel_id',
        'games_channel_id',
        'locale',
        'paused',
        'moderation_mode',
    ];

    protected function casts(): array
    {
        return [
            'paused' => 'boolean',
            'moderation_mode' => DiscordModerationMode::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $guild) {
            if (empty($guild->id)) {
                $guild->id = (string) Str::orderedUuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /**
     * The roundup user who installed/configured the bot in this guild.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Per-organizer opt-in rows for this guild (D119 discovery gate).
     *
     * @return HasMany<DiscordGuildOrganizer, $this>
     */
    public function organizers(): HasMany
    {
        return $this->hasMany(DiscordGuildOrganizer::class, 'guild_id');
    }

    /**
     * Cards posted to this guild (tracked message ids).
     *
     * @return HasMany<DiscordCardMessage, $this>
     */
    public function cardMessages(): HasMany
    {
        return $this->hasMany(DiscordCardMessage::class, 'guild_id');
    }

    // ── Convenience ────────────────────────────────────

    /**
     * Whether the landlord has paused all posting to this guild.
     */
    public function getIsPausedAttribute(): bool
    {
        return (bool) $this->paused;
    }
}
