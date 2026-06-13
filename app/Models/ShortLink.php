<?php

namespace App\Models;

use Database\Factories\ShortLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property int|null $count
 * @property string $code
 * @property string $original_url
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShortLink extends Model
{
    /** @use HasFactory<ShortLinkFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'code',
        'url',
        'linkable_type',
        'linkable_id',
        'user_id',
        'label',
        'purpose',
        'expires_at',
        'max_hits',
        'hit_count',
        'last_hit_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'hit_count' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Cache invalidation on update — clear both old and new code keys.
        static::updating(function (self $link) {
            $originalCode = $link->getOriginal('code');
            Cache::forget('short_link:'.(is_string($originalCode) ? $originalCode : ''));
            if ($link->isDirty('code')) {
                Cache::forget("short_link:{$link->code}");
            }
            Cache::forget("short_link_id:{$link->id}");
        });

        static::deleting(function (self $link) {
            Cache::forget("short_link:{$link->code}");
            Cache::forget("short_link_id:{$link->id}");
        });
    }

    // ── Relationships ────────────────────────────────────

    /** @return MorphTo<Model, $this> */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ShortLinkHit, $this>
     */
    public function hits(): HasMany
    {
        return $this->hasMany(ShortLinkHit::class);
    }

    // ── Accessors ────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasHitCap(): bool
    {
        return $this->max_hits !== null && $this->hit_count >= $this->max_hits;
    }
}
