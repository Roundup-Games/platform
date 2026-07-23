<?php

namespace App\Models;

use App\Notifications\SessionReminder;
use Database\Factories\GameReminderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single organizer-authored custom session reminder for a Game.
 *
 * Decision D125 (hybrid model): the two built-in reminders (24h / 1h) live as
 * timestamp columns on {@see Game}; organizer-authored extras live here as
 * rows (up to 5 per game, enforced in the organizer UI — T06). Each row is an
 * absolute `send_at` plus an optional custom `message`; the SendSessionReminders
 * sweep (T06) dispatches due rows through {@see SessionReminder}
 * with the custom copy, then stamps `sent_at` for dedup.
 *
 * Reusing NotificationCategory::SessionReminder at the dispatch site keeps user
 * preference filtering / block-lists / structured logging intact (MEM855).
 *
 * @property string $id
 * @property string $game_id
 * @property Carbon $send_at
 * @property string|null $message
 * @property int|null $offset_minutes
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GameReminder extends Model
{
    /** @use HasFactory<GameReminderFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'game_id',
        'send_at',
        'message',
        'offset_minutes',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'send_at' => 'datetime',
            'offset_minutes' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $reminder) {
            if (empty($reminder->id)) {
                $reminder->id = (string) Str::uuid();
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

    // ── Scopes ─────────────────────────────────────────

    /**
     * Reminders that have not yet been dispatched (`sent_at IS NULL`).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnsent(Builder $query): Builder
    {
        return $query->whereNull('sent_at');
    }

    /**
     * Unsent reminders whose absolute send time has arrived (`send_at <= now`).
     * This is the SendSessionReminders custom-sweep predicate (T06).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->unsent()->where('send_at', '<=', now());
    }

    // ── Accessors ──────────────────────────────────────

    /**
     * Whether this reminder has been dispatched (sent_at stamped).
     */
    public function getIsSentAttribute(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * Whether this reminder's send time has arrived but it has not been sent.
     */
    public function getIsDueAttribute(): bool
    {
        return $this->sent_at === null
            && $this->send_at->isPast();
    }

    /**
     * Mark this reminder as sent by stamping sent_at (dedup marker).
     */
    public function markSent(): bool
    {
        return $this->forceFill(['sent_at' => now()])->save();
    }
}
