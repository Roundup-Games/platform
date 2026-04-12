<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GameSystem extends Model
{
    use HasFactory;
    protected $fillable = [
        'name', 'slug', 'description', 'images', 'min_players', 'max_players',
        'optimal_players', 'average_play_time', 'age_rating', 'complexity_rating', 'year_released',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'min_players' => 'integer',
            'max_players' => 'integer',
            'optimal_players' => 'integer',
            'average_play_time' => 'integer',
            'year_released' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $gameSystem) {
            if (empty($gameSystem->slug)) {
                $gameSystem->slug = Str::slug($gameSystem->name);
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(GameSystemCategory::class, 'game_system_category');
    }

    public function mechanics(): BelongsToMany
    {
        return $this->belongsToMany(GameSystemMechanic::class, 'game_system_mechanic');
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function favoredByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_game_system_preferences')
            ->wherePivot('preference_type', 'favorite');
    }
}
