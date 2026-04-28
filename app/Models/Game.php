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
        'reminder_sent_at', 'recap', 'min_reliability_preference',
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
            'reminder_sent_at' => 'datetime',
            'min_reliability_preference' => 'decimal:2',
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

    public function sessionDebriefings(): HasMany
    {
        return $this->hasMany(SessionDebriefing::class);
    }

    public function activeSessionZeroSurvey(): ?SessionZeroSurvey
    {
        return $this->sessionZeroSurveys()->active()->first();
    }

    // ── Debriefing Helpers ─────────────────────────────

    /**
     * Check if this game has debriefing-capable safety tools selected.
     */
    public function hasDebriefingTools(): bool
    {
        if (! $this->safety_rules || ! is_array($this->safety_rules)) {
            return false;
        }

        return in_array('debriefing', $this->safety_rules)
            || in_array('stars-and-wishes', $this->safety_rules);
    }

    /**
     * Get the structured debriefing prompts based on selected safety tools.
     *
     * @return array<string, array{prompt: string, confidential?: bool}>
     */
    public function getDebriefingPrompts(): array
    {
        $prompts = [];

        if (! $this->safety_rules || ! is_array($this->safety_rules)) {
            return $prompts;
        }

        if (in_array('debriefing', $this->safety_rules)) {
            $prompts['what_went_well'] = [
                'prompt' => __('games.debriefing_prompt_what_went_well'),
            ];
            $prompts['what_to_change'] = [
                'prompt' => __('games.debriefing_prompt_what_to_change'),
            ];
            $prompts['safety_concerns'] = [
                'prompt' => __('games.debriefing_prompt_safety_concerns'),
                'confidential' => true,
            ];
        }

        if (in_array('stars-and-wishes', $this->safety_rules)) {
            $prompts['star'] = [
                'prompt' => __('games.debriefing_prompt_star'),
            ];
            $prompts['wish'] = [
                'prompt' => __('games.debriefing_prompt_wish'),
            ];
        }

        return $prompts;
    }

    /**
     * Determine the primary debriefing tool type for this game.
     * Prefers 'debriefing' over 'stars-and-wishes' when both are present.
     */
    public function getDebriefingToolType(): ?string
    {
        if (! $this->safety_rules || ! is_array($this->safety_rules)) {
            return null;
        }

        if (in_array('debriefing', $this->safety_rules)) {
            return 'debriefing';
        }

        if (in_array('stars-and-wishes', $this->safety_rules)) {
            return 'stars-and-wishes';
        }

        return null;
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

    // ── Bench ──────────────────────────────────────────

    /**
     * Campaign sessions support bench mode.
     * Standalone games use waitlist instead.
     */
    public function isBenchMode(): bool
    {
        return $this->campaign_id !== null;
    }
}
