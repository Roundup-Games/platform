<?php

namespace App\Models;

use App\Enums\VenueType;
use App\Services\Geohash;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RalphJSmit\Laravel\SEO\SchemaCollection;
use RalphJSmit\Laravel\SEO\Support\HasSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;
use Spatie\SchemaOrg\LocalBusiness;
use Spatie\SchemaOrg\PostalAddress;

/**
 * @property string $id
 * @property string $name
 * @property string|null $slug
 * @property string|null $description
 * @property string|null $address
 * @property string|null $city
 * @property string|null $postal_code
 * @property string|null $country
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $geohash_4
 * @property string|null $place_id
 * @property string|null $source
 * @property array<string, mixed>|null $metadata
 * @property bool $is_verified
 * @property VenueType|null $venue_type
 * @property array<string, mixed>|null $venue_metadata
 * @property string|null $website_url
 * @property string|null $managed_by
 * @property string|null $venue_notes
 * @property float|null $average_rating
 * @property int $review_count
 * @property string $drift_status
 * @property Carbon|null $drift_detected_at
 * @property array<string, mixed>|null $drift_metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    use HasSEO;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'slug',
        'description',
        'address',
        'city',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'geohash_4',
        'place_id',
        'source',
        'metadata',
        'is_verified',
        'venue_type',
        'venue_notes',
        'website_url',
        'managed_by',
        'venue_metadata',
        'average_rating',
        'review_count',
        'drift_status',
        'drift_detected_at',
        'drift_metadata',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'metadata' => 'array',
            'is_verified' => 'boolean',
            'venue_type' => VenueType::class,
            'venue_metadata' => 'array',
            'average_rating' => 'decimal:2',
            'review_count' => 'integer',
            'drift_status' => 'string',
            'drift_detected_at' => 'datetime',
            'drift_metadata' => 'array',
        ];
    }

    // ── Model Events ───────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (self $location) {
            if (empty($location->id)) {
                $location->id = (string) Str::orderedUuid();
            }
        });

        static::saving(function (self $location) {
            if ($location->latitude && $location->longitude) {
                $location->geohash_4 = Geohash::tilePrefix(
                    (float) $location->latitude,
                    (float) $location->longitude,
                    4
                );
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    /**
     * @return HasMany<Game, $this>
     */
    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function managedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by');
    }

    /**
     * Polymorphic venue reviews (reviewable_type = Location).
     *
     * Shares the polymorphic Review model with Game/Campaign reviews
     * (MEM720/D083). Venue reviews set gm_profile_id = null.
     *
     * @return MorphMany<Review, $this>
     */
    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVerified(Builder $query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByVenueType(Builder $query, string $type)
    {
        return $query->where('venue_type', $type);
    }

    // ── Scopes ─────────────────────────────────────────

    /**
     * Scope to locations within a bounding box.
     * Used for proximity queries per D037 (10km bounding box approximation).
     *
     * @param  float  $minLat  Southern boundary latitude
     * @param  float  $maxLat  Northern boundary latitude
     * @param  float  $minLng  Western boundary longitude
     * @param  float  $maxLng  Eastern boundary longitude
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithinBounds(Builder $query, float $minLat, float $maxLat, float $minLng, float $maxLng): Builder
    {
        return $query->whereBetween('latitude', [$minLat, $maxLat])
            ->whereBetween('longitude', [$minLng, $maxLng]);
    }

    /**
     * Find or create a location by place_id for deduplication.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function findOrCreateByPlaceId(string $placeId, array $attributes = []): ?self
    {
        return static::firstOrCreate(
            ['place_id' => $placeId],
            $attributes
        );
    }

    public function isVenue(): bool
    {
        return (bool) $this->is_verified;
    }

    /**
     * Public venue pages are slug-routed (/{locale}/venue/{slug}).
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ── Slug Generation ────────────────────────────────

    /**
     * Generate a URL-friendly slug from a name.
     *
     * Byte-identical in output to {@see User::generateSlug()} — the two
     * implementations share the same transliteration map and step sequence so
     * users and venues slug consistently. Future extraction into a shared
     * trait is tracked as a follow-up; until then @see User::transliterate.
     */
    public static function generateSlug(string $name): string
    {
        // Transliterate to ASCII first (ü→ue, ö→oe, ä→ae, é→e, etc.)
        $slug = static::transliterate($name);
        // Remove anything that's not ASCII letters, numbers, spaces, or hyphens
        $slug = (string) preg_replace('/[^a-zA-Z0-9\s-]/', '', $slug);
        // Replace spaces with hyphens
        $slug = (string) preg_replace('/\s+/', '-', trim($slug));
        // Collapse consecutive hyphens
        $slug = (string) preg_replace('/-+/', '-', $slug);
        // Lowercase
        $slug = mb_strtolower($slug);
        // Trim leading/trailing hyphens
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * Transliterate Unicode characters to ASCII equivalents.
     *
     * Identical map + iconv fallback as {@see User::transliterate()} — kept in
     * sync so slug output matches User byte-for-byte across platforms.
     */
    protected static function transliterate(string $text): string
    {
        $map = [
            // German expansions (multi-char)
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
            // Nordic
            'æ' => 'ae', 'ø' => 'oe', 'å' => 'aa',
            'Æ' => 'Ae', 'Ø' => 'Oe', 'Å' => 'Aa',
            // Latin accented vowels
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'ñ' => 'n', 'ç' => 'c',
            // Slavic / Central European
            'ž' => 'z', 'š' => 's', 'č' => 'c', 'ř' => 'r',
            'ď' => 'd', 'ť' => 't', 'ň' => 'n',
            'ł' => 'l', 'ś' => 's', 'ź' => 'z',
            'Ž' => 'Z', 'Š' => 'S', 'Č' => 'C', 'Ř' => 'R',
            'Ď' => 'D', 'Ť' => 'T', 'Ň' => 'N',
            'Ł' => 'L', 'Ś' => 'S', 'Ź' => 'Z',
            // Uppercase accented vowels
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U',
            'Ý' => 'Y', 'Ñ' => 'N', 'Ç' => 'C',
        ];
        $text = strtr($text, $map);

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return $transliterated !== false ? $transliterated : $text;
    }

    /**
     * Generate a unique slug for the given name, appending incremental digits on collision.
     *
     * Mirrors {@see User::generateUniqueSlug()}; the only divergence is the
     * empty-base fallback, which is 'venue' here (vs 'user' for users).
     */
    public static function generateUniqueSlug(string $name, ?string $ignoreId = null): string
    {
        $baseSlug = static::generateSlug($name);

        if ($baseSlug === '') {
            $baseSlug = 'venue';
        }

        $slug = $baseSlug;
        $counter = 1;

        $query = static::where('slug', $slug);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        while ($query->exists()) {
            $counter++;
            $slug = $baseSlug.'-'.$counter;

            $query = static::where('slug', $slug);

            if ($ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }
        }

        return $slug;
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Calculate approximate distance in km to another point using Haversine formula.
     */
    public function distanceTo(float $lat, float $lng): float
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat - (float) $this->latitude);
        $dLng = deg2rad($lng - (float) $this->longitude);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad((float) $this->latitude)) * cos(deg2rad($lat))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * asin(sqrt($a));

        return round($earthRadius * $c, 2);
    }

    /**
     * Get the full address as a formatted string.
     */
    public function fullAddress(): string
    {
        $parts = array_filter([
            $this->address,
            $this->postal_code ? "{$this->postal_code} {$this->city}" : $this->city,
        ]);

        return implode(', ', $parts);
    }

    // ── SEO ────────────────────────────────────────────

    /**
     * Dynamic SEO for the public venue page (M053/S02).
     *
     * Safe to emit 'index, follow' unconditionally because getDynamicSEOData()
     * is only rendered for venues that cleared the isPublicVenuePage() 404 gate
     * in VenueDetail::mount() — private/unverified/`other` locations never
     * reach a route that calls seo()->for($location). The LocalBusiness schema
     * mirrors Team's Organization approach (extends Place+Organization so it
     * carries both the address and the business identity).
     */
    public function getDynamicSEOData(): SEOData
    {
        $description = $this->description
            ? Str::limit(strip_tags((string) $this->description), 160)
            : trim("{$this->name}".($this->city ? " — {$this->city}" : '').($this->country ? ", {$this->country}" : ''));

        $schema = SchemaCollection::initialize();

        $business = (new LocalBusiness)
            ->name($this->name)
            ->url(route('venues.detail', [
                'locale' => app()->getLocale(),
                'slug' => $this->slug,
            ]));

        // The schema description is only meaningful when content exists — an
        // empty value would produce a useless description node, and the
        // LocalBusiness::description() type contract is non-nullable anyway.
        if ($this->description) {
            $business->description(Str::limit(strip_tags((string) $this->description), 500));
        }

        $address = (new PostalAddress);
        if ($this->address) {
            $address->streetAddress($this->address);
        }
        if ($this->postal_code) {
            $address->postalCode($this->postal_code);
        }
        if ($this->city) {
            $address->addressLocality($this->city);
        }
        if ($this->country) {
            $address->addressCountry($this->country);
        }
        $business->address($address);

        $sameAs = [];
        if ($this->website_url) {
            $sameAs[] = $this->website_url;
        }
        if (! empty($sameAs)) {
            $business->sameAs($sameAs);
        }

        $schema->push($business->toArray());

        return new SEOData(
            title: $this->name,
            description: $description ?: null,
            robots: 'index, follow',
            schema: $schema,
        );
    }
}
