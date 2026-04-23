<?php

namespace App\Models;

use App\Enums\GameType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Game extends Model
{
    use HasFactory;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'owner_id', 'campaign_id', 'game_system_id', 'name', 'date_time',
        'description', 'expected_duration', 'price', 'language', 'location', 'location_id',
        'status', 'game_type', 'minimum_requirements', 'visibility', 'safety_rules',
        'min_players', 'max_players', 'experience_level', 'complexity', 'vibe_flags',
    ];

    protected function casts(): array
    {
        return [
            'date_time' => 'datetime',
            'expected_duration' => 'float',
            'price' => 'float',
            'location' => 'array',
            'minimum_requirements' => 'array',
            'safety_rules' => 'array',
            'min_players' => 'integer',
            'max_players' => 'integer',
            'complexity' => 'decimal:2',
            'vibe_flags' => 'array',
            'game_type' => GameType::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $game) {
            if (empty($game->id)) {
                $game->id = (string) Str::uuid();
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function gameSystem(): BelongsTo
    {
        return $this->belongsTo(GameSystem::class);
    }

    public function linkedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(GameParticipant::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(GameApplication::class);
    }

    public function sessionZeroSurveys(): HasMany
    {
        return $this->hasMany(SessionZeroSurvey::class);
    }

    public function activeSessionZeroSurvey(): ?SessionZeroSurvey
    {
        return $this->sessionZeroSurveys()->active()->first();
    }

    // ── Scopes ─────────────────────────────────────────

    public function scopePublic($query)
    {
        return $query->where('visibility', 'public');
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('date_time', '>', now())->orderBy('date_time');
    }
}
