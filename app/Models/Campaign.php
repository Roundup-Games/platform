<?php

namespace App\Models;

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

    protected $fillable = [
        'owner_id', 'game_system_id', 'location_id', 'name', 'description', 'images',
        'recurrence', 'time_of_day', 'session_duration', 'price_per_session',
        'language', 'status', 'minimum_requirements', 'visibility', 'safety_rules',
        'min_players', 'max_players', 'experience_level', 'complexity', 'vibe_flags',
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

    // ── Bench ──────────────────────────────────────────

    /**
     * Campaigns always support bench mode.
     */
    public function isBenchMode(): bool
    {
        return true;
    }
}
