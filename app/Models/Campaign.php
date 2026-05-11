<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\Visibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Campaign extends Model
{
    use HasFactory;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $attributes = [
        'bench_mode' => true,
    ];

    protected $fillable = [
        'owner_id', 'game_system_id', 'location_id', 'name', 'description', 'images',
        'recurrence', 'time_of_day', 'session_duration', 'price_per_session',
        'language', 'status', 'minimum_requirements', 'visibility', 'safety_rules',
        'min_players', 'max_players', 'experience_level', 'complexity', 'vibe_flags',
        'share_token', 'share_token_expires_at', 'bench_mode',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'session_duration' => 'float',
            'price_per_session' => 'float',
            'minimum_requirements' => 'array',
            'safety_rules' => 'array',
            'min_players' => 'integer',
            'max_players' => 'integer',
            'complexity' => 'decimal:2',
            'vibe_flags' => 'array',
            'visibility' => Visibility::class,
            'status' => CampaignStatus::class,
            'share_token' => 'string',
            'share_token_expires_at' => 'datetime',
            'bench_mode' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $campaign) {
            if (empty($campaign->id)) {
                $campaign->id = (string) Str::uuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function gameSystem(): BelongsTo
    {
        return $this->belongsTo(GameSystem::class);
    }

    public function linkedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CampaignParticipant::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(CampaignApplication::class);
    }

    // ── Share Token ────────────────────────────────────

    /**
     * Check whether the current request carries a valid share token for this entity.
     * Validates that: the query param 'share' matches the stored token AND the token hasn't expired.
     */
    public function hasValidShareToken(?string $token = null): bool
    {
        $token = $token ?? request()->query('share');

        if (! $token || ! $this->share_token) {
            return false;
        }

        if ($this->share_token_expires_at !== null && $this->share_token_expires_at->isPast()) {
            return false;
        }

        return hash_equals($this->share_token, $token);
    }

    // ── Scopes ─────────────────────────────────────────

    /**
     * Scope to campaigns visible to a given user (or guest).
     *
     * Guests see public only. Authenticated users see public + protected.
     * Private campaigns are never included in listings (handled by policies).
     */
    public function scopeVisibleTo($query, ?User $viewer = null)
    {
        if ($viewer === null) {
            return $query->where('visibility', 'public');
        }

        return $query->where(function ($q) {
            $q->where('visibility', 'public')
              ->orWhere('visibility', 'protected');
        });
    }

    // ── Bench ──────────────────────────────────────────

    /**
     * Check if this entity uses bench mode (overflow to bench) vs waitlist (FIFO queue).
     * Reads from the bench_mode column.
     */
    public function isBenchMode(): bool
    {
        return (bool) $this->bench_mode;
    }
}
