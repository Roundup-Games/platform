<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use App\Traits\StringMorphMediaKey;

class GameSystem extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use StringMorphMediaKey { StringMorphMediaKey::media insteadof InteractsWithMedia; }
    protected $fillable = [
        'name', 'slug', 'description', 'images', 'min_players', 'max_players',
        'optimal_players', 'average_play_time', 'age_rating', 'complexity_rating', 'year_released',
        'bgg_id', 'bgg_type', 'thumbnail_url', 'base_game_id',
        'bgg_average_rating', 'bgg_bayes_average', 'bgg_rank',
        'bgg_users_rated', 'bgg_average_weight', 'bgg_last_synced_at',
        'type', 'source', 'source_slug', 'creator', 'player_range',
        'sp_rating', 'sp_review_count', 'platform_score', 'faq_content', 'external_links',
        'showcases', 'instructions',
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
            'bgg_id' => 'integer',
            'base_game_id' => 'integer',
            'bgg_average_rating' => 'decimal:2',
            'bgg_bayes_average' => 'decimal:2',
            'bgg_rank' => 'integer',
            'bgg_users_rated' => 'integer',
            'bgg_average_weight' => 'decimal:2',
            'bgg_last_synced_at' => 'datetime',
            'type' => 'string',
            'source' => 'string',
            'sp_rating' => 'decimal:2',
            'sp_review_count' => 'integer',
            'platform_score' => 'integer',
            'faq_content' => 'array',
            'external_links' => 'array',
            'showcases' => 'array',
            'instructions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $gameSystem) {
            if (empty($gameSystem->slug)) {
                $gameSystem->slug = Str::slug($gameSystem->name);
            }
            if (empty($gameSystem->type)) {
                $gameSystem->type = 'boardgame';
            }
        });
    }

    // ── Scopes ─────────────────────────────────────────

    public function scopeTtrpg($query)
    {
        $query->where('type', 'ttrpg');
    }

    public function scopeBoardgame($query)
    {
        $query->where('type', 'boardgame');
    }

    // ── Accessors ──────────────────────────────────────

    public function isTtrpg(): bool
    {
        return $this->type === 'ttrpg';
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?\Spatie\MediaLibrary\MediaCollections\Models\Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(150)
            ->height(150)
            ->sharpen(10);
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

    public function families(): BelongsToMany
    {
        return $this->belongsToMany(GameSystemFamily::class, 'game_system_family');
    }

    public function designers(): BelongsToMany
    {
        return $this->belongsToMany(GameSystemDesigner::class, 'game_system_designer');
    }

    public function publishers(): BelongsToMany
    {
        return $this->belongsToMany(GameSystemPublisher::class, 'game_system_publisher');
    }

    public function baseGame(): BelongsTo
    {
        return $this->belongsTo(GameSystem::class, 'base_game_id');
    }

    public function expansions(): HasMany
    {
        return $this->hasMany(GameSystem::class, 'base_game_id');
    }

    public function bggSyncLogs(): HasMany
    {
        return $this->hasMany(BggSyncLog::class);
    }
}
