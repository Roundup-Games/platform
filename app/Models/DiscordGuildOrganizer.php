<?php

namespace App\Models;

use Database\Factories\DiscordGuildOrganizerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Per-(guild, organizer) D119 opt-in row.
 *
 * @property string $id
 * @property string $guild_id roundup DiscordGuild id
 * @property string $user_id roundup organizer (User) id
 * @property bool $publish_enabled
 * @property Carbon|null $opted_in_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DiscordGuildOrganizer extends Model
{
    /** @use HasFactory<DiscordGuildOrganizerFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'guild_id',
        'user_id',
        'publish_enabled',
        'opted_in_at',
    ];

    protected function casts(): array
    {
        return [
            'publish_enabled' => 'boolean',
            'opted_in_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $organizer) {
            if (empty($organizer->id)) {
                $organizer->id = (string) Str::orderedUuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /**
     * The roundup DiscordGuild this opt-in belongs to.
     *
     * @return BelongsTo<DiscordGuild, $this>
     */
    public function guild(): BelongsTo
    {
        return $this->belongsTo(DiscordGuild::class, 'guild_id');
    }

    /**
     * The roundup organizer (User) who opted in.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
