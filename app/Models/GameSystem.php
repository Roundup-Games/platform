<?php

namespace App\Models;

use App\Traits\StringMorphMediaKey;
use Database\Factories\GameSystemFactory;
use Escalated\Laravel\Concerns\PresentsAsTicketSubject;
use Escalated\Laravel\Contracts\TicketSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RalphJSmit\Laravel\SEO\SchemaCollection;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\SchemaOrg\AggregateRating;
use Spatie\SchemaOrg\Product;
use Spatie\Translatable\HasTranslations;

/**
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property string|null $type
 * @property string|null $slug
 * @property string|null $cover_image_url
 * @property string|null $base_game_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<int, array{question: string, answer: string}>|null $faq_content
 */
class GameSystem extends Model implements HasMedia, TicketSubject
{
    /** @use HasFactory<GameSystemFactory> */
    use HasFactory;

    use HasSEO;
    use HasTranslations;
    use PresentsAsTicketSubject;

    /**
     * Deep link into the host app for this game system when attached as a
     * ticket subject. GameSystemDetail is slug-based.
     */
    public function ticketSubjectUrl(): ?string
    {
        return route('game-systems.show', $this, absolute: false);
    }

    use InteractsWithMedia;
    use StringMorphMediaKey { StringMorphMediaKey::media insteadof InteractsWithMedia; }

    /** @var array<int, string> */
    public array $translatable = ['name', 'description'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
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
            'base_game_id' => 'string',
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
            if (empty($gameSystem->id)) {
                $gameSystem->id = (string) Str::orderedUuid();
            }
            if (empty($gameSystem->slug)) {
                $gameSystem->slug = $gameSystem->resolveFallbackSlug();
            }
            if (empty($gameSystem->type)) {
                $gameSystem->type = 'boardgame';
            }
        });

        // Defense-in-depth for the blank-slug defect: `slug` is NOT NULL but
        // the column accepts an empty string, and GameSystemResource exposes it
        // as a non-required TextInput — so an admin edit can clear it. A blank
        // slug makes route('game-systems.show') throw UrlGenerationException,
        // 500-ing any partial that surfaces the record as a base game or
        // expansion. Re-derive on update if it was blanked.
        static::updating(function (self $gameSystem) {
            if (empty($gameSystem->slug)) {
                $gameSystem->slug = $gameSystem->resolveFallbackSlug();
            }
        });
    }

    /**
     * Derive a routable slug when none was supplied or it was cleared.
     *
     * Names that slugify to nothing (e.g. non-latin BGG titles) must not leave
     * a blank slug — it would break route('game-systems.show'). Falls back to a
     * stable, id-based slug so the record is always routable.
     */
    protected function resolveFallbackSlug(): string
    {
        $name = $this->getTranslation('name', 'en');
        $slug = Str::slug(is_string($name) ? $name : '');

        return $slug !== '' ? $slug : 'game-system-'.$this->id;
    }

    // ── Scopes ─────────────────────────────────────────

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeTtrpg(Builder $query): Builder
    {
        return $query->where('type', 'ttrpg');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBoardgame(Builder $query): Builder
    {
        return $query->where('type', 'boardgame');
    }

    /**
     * Build a flat [id => label] option map for Filament selects.
     *
     * game_systems has a `json`-typed `images` column, so Filament's default
     * relationship-option query — `SELECT DISTINCT game_systems.*` (with a
     * belongsToMany left join) — throws "could not identify an equality
     * operator for type json" because the `json` type has no equality
     * operator. This selects only `id` and the extracted `name->>'en'` text,
     * avoiding `*`, the json column, and the DISTINCT altogether.
     *
     * @return array<string, string>
     */
    public static function labelOptions(?string $search = null, int $limit = 50): array
    {
        $rows = static::query()
            ->when($search !== null && $search !== '', fn (Builder $q) => $q->whereRaw("name->>'en' ILIKE ?", ["%{$search}%"]))
            ->orderByRaw("name->>'en'")
            ->limit($limit)
            ->select('id')
            ->selectRaw("name->>'en' AS label")
            ->get();

        $options = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $id = isset($data['id']) ? (string) $data['id'] : '';
            $rawLabel = $data['label'] ?? null;
            $label = is_string($rawLabel) && $rawLabel !== '' ? $rawLabel : $id;
            if ($id !== '') {
                $options[$id] = $label;
            }
        }

        return $options;
    }

    /**
     * Resolve labels for a specific set of ids (Filament getOptionLabelsUsing).
     *
     * Same rationale as {@see labelOptions()}: select only the columns we
     * need so PostgreSQL never DISTINCTs over the `json` images column.
     *
     * @param  array<string>  $ids
     * @return array<string, string>
     */
    public static function labelsForIds(array $ids): array
    {
        $ids = array_values(array_filter($ids, fn ($v) => filled($v)));
        if ($ids === []) {
            return [];
        }

        $rows = static::query()
            ->whereIn('id', $ids)
            ->orderByRaw("name->>'en'")
            ->select('id')
            ->selectRaw("name->>'en' AS label")
            ->get();

        $options = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $id = isset($data['id']) ? (string) $data['id'] : '';
            $rawLabel = $data['label'] ?? null;
            $label = is_string($rawLabel) && $rawLabel !== '' ? $rawLabel : $id;
            if ($id !== '') {
                $options[$id] = $label;
            }
        }

        return $options;
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

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(150)
            ->height(150)
            ->sharpen(10);
    }

    /**
     * Resolved cover image URL — prefers local media, falls back to BGG thumbnail_url.
     *
     * Validates that the media file actually exists on disk before returning its URL,
     * avoiding broken <img> src attributes when conversion files are missing.
     */
    public function coverImageUrl(string $conversionName = ''): ?string
    {
        $media = $this->getFirstMedia('cover');

        if ($media) {
            $path = $conversionName
                ? $media->getPath($conversionName)
                : $media->getPath();

            if ($path && file_exists($path)) {
                return $conversionName
                    ? $media->getUrl($conversionName)
                    : $media->getUrl();
            }
        }

        return $this->thumbnail_url;
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return BelongsToMany<GameSystemCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(GameSystemCategory::class, 'game_system_category');
    }

    /**
     * @return BelongsToMany<GameSystemMechanic, $this>
     */
    public function mechanics(): BelongsToMany
    {
        return $this->belongsToMany(GameSystemMechanic::class, 'game_system_mechanic');
    }

    /** @return BelongsToMany<Game, $this> */
    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'game_game_system');
    }

    /** @return BelongsToMany<Campaign, $this> */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(Campaign::class, 'campaign_game_system');
    }

    /** @return BelongsToMany<User, $this> */
    public function favoredByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_game_system_preferences')
            ->wherePivot('preference_type', 'favorite');
    }

    /** @return BelongsToMany<GameSystemFamily, $this> */
    public function families(): BelongsToMany
    {
        return $this->belongsToMany(GameSystemFamily::class, 'game_system_family');
    }

    /** @return BelongsToMany<GameSystemDesigner, $this> */
    public function designers(): BelongsToMany
    {
        return $this->belongsToMany(GameSystemDesigner::class, 'game_system_designer');
    }

    /** @return BelongsToMany<GameSystemPublisher, $this> */
    public function publishers(): BelongsToMany
    {
        return $this->belongsToMany(GameSystemPublisher::class, 'game_system_publisher');
    }

    /** @return BelongsTo<GameSystem, $this> */
    public function baseGame(): BelongsTo
    {
        return $this->belongsTo(GameSystem::class, 'base_game_id');
    }

    /** @return HasMany<GameSystem, $this> */
    public function expansions(): HasMany
    {
        return $this->hasMany(GameSystem::class, 'base_game_id');
    }

    /** @return HasMany<BggSyncLog, $this> */
    public function bggSyncLogs(): HasMany
    {
        return $this->hasMany(BggSyncLog::class);
    }

    // ── SEO ────────────────────────────────────────────

    public function getDynamicSEOData(): SEOData
    {
        $schema = SchemaCollection::initialize();

        // Product schema
        $product = (new Product)
            ->name($this->name)
            ->description(Str::limit(strip_tags((string) $this->description), 500) ?: '');

        if ($imageUrl = $this->coverImageUrl()) {
            $product->image($imageUrl);
        }

        if ($this->id) {
            $product->sku((string) $this->id);
        }

        if ($this->creator) {
            $product->brand($this->creator);
        }

        // AggregateRating (conditional — only if ratings exist)
        $ratingValue = $this->sp_rating ?? $this->bgg_average_rating;
        $reviewCount = $this->sp_review_count ?? $this->bgg_users_rated;

        if ($ratingValue && $ratingValue > 0) {
            $bestRating = $this->sp_rating ? 5 : 10; // Platform scale is 5, BGG scale is 10
            $product->aggregateRating(
                (new AggregateRating)
                    ->ratingValue((float) $ratingValue)
                    ->bestRating($bestRating)
                    ->worstRating(1)
                    ->reviewCount(max(1, (int) ($reviewCount ?? 1)))
            );
        }

        $schema->push($product->toArray());

        // FAQPage (conditional — only if FAQ content exists)
        if (! empty($this->faq_content)) {
            $faqItems = $this->faq_content;
            $schema->addFaqPage(function ($faqSchema) use ($faqItems) {
                foreach ($faqItems as $faq) {
                    $faqSchema->addQuestion($faq['question'], $faq['answer']);
                }
            });
        }

        return new SEOData(
            title: $this->name,
            description: Str::limit(strip_tags($this->description ?? ''), 160) ?: null,
            image: $this->coverImageUrl() ?: asset('images/og-default.jpg'),
            robots: 'index, follow',
            schema: $schema,
        );
    }
}
