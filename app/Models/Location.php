<?php

namespace App\Models;

use App\Enums\VenueType;
use App\Models\Concerns\HasEntitySeo;
use App\Models\Concerns\HasPlatformUuid;
use App\Services\CityRegistryService;
use App\Services\Geohash;
use App\Services\LocationDisclosureService;
use App\Services\ProximityQuery;
use App\Services\SlugService;
use Database\Factories\LocationFactory;
use Escalated\Laravel\Concerns\PresentsAsTicketSubject;
use Escalated\Laravel\Contracts\TicketSubject;
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
use Spatie\Translatable\HasTranslations;

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
 * @property float|null $distance_km Virtual Haversine distance from the viewer; set by proximity listings (directory, hubs)
 * @property string $drift_status
 * @property Carbon|null $drift_detected_at
 * @property array<string, mixed>|null $drift_metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Location extends Model implements TicketSubject
{
    use HasEntitySeo;

    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    use HasPlatformUuid;
    // Location has no public-facing route; ticketSubjectUrl() returns null
    // (the trait default), so subjects render without a link in the ticket UI.

    use HasSEO;
    use HasTranslations;
    use PresentsAsTicketSubject;

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

    /** @var array<int, string> */
    public array $translatable = ['seo_title', 'seo_description'];

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
        static::saving(function (self $location) {
            if ($location->latitude && $location->longitude) {
                $location->geohash_4 = Geohash::tilePrefix(
                    (float) $location->latitude,
                    (float) $location->longitude,
                    4
                );
            }

            // Auto-generate a resolvable slug for every public-venue-page-
            // eligible location — the forward-looking invariant that the one-
            // time 2026_06_15 / 2026_06_16 backfills only established for rows
            // that existed at deploy time. Without this, any venue verified or
            // claimed AFTER those migrations shipped with slug = null and was
            // silently invisible across the whole platform: <x-venue-link>
            // rendered nothing, /venue/{slug} 404'd, and the venues sitemap
            // omitted it. (The Yorckschlösschen path: venue created, later
            // verified by an admin in Filament -> an UPDATE, not a create, so
            // this hook MUST fire on save generally, not just on create.)
            //
            // Never overwrite an existing slug: public URLs, SEO, and the
            // sitemap must stay stable on rename, and the backfills stay
            // idempotent. Only fills when empty, so the rule is safe to run
            // repeatedly. Eligibility is delegated to the single authority -
            // LocationDisclosureService::isPublicVenuePage() - so "which
            // locations get a slug" can never drift from "which get a public
            // page / a clickable name / a sitemap entry".
            if (! filled($location->slug)
                && filled($location->name)
                && app(LocationDisclosureService::class)->isPublicVenuePage($location)
            ) {
                // For a brand-new record, `saving` fires BEFORE the `creating`
                // hook that sets the UUID, so the id may still be null here.
                // generateUniqueSlug's $ignoreId only excludes self on UPDATE;
                // a not-yet-persisted row cannot collide with itself, so a
                // null ignoreId is correct and safe for the create path while
                // a real id (present on every existing record) is used on update.
                $ignoreId = $location->exists ? $location->id : null;

                $location->slug = static::generateUniqueSlug($location->name, $ignoreId);
            }
        });

        // City registry link (D171): after the saving hook above has
        // computed geohash_4, derive the cluster (slug + 3-char region)
        // and relink city_id through the single write authority. Mass
        // update inside — no events, so it cannot recurse; the registry
        // service flushes the affected hub caches itself.
        static::saved(function (self $location): void {
            if ($location->wasChanged(['city', 'geohash_4', 'latitude', 'longitude']) || $location->city_id === null) {
                app(CityRegistryService::class)->syncLocation($location);
            }
        });
    }

    /**
     * Geocoder provenance is trimmed on write — "Berlin " and "Berlin"
     * must land in one cluster identity, not luck. Trim only: casing and
     * spelling stay exactly as the geocoder returned them. (Classic
     * mutator: the city() name is reserved for the registry relation.)
     */
    protected function setCityAttribute(?string $value): void
    {
        $this->attributes['city'] = is_string($value) ? trim($value) : $value;
    }

    // ── Relationships ──────────────────────────────────

    /**
     * The registry entity this location's cluster resolves to (D171).
     * Nullable for rows that are not yet geocoded (no region derivable).
     *
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

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
     * Scope to locations eligible for a public venue page — the query form of
     * {@see LocationDisclosureService::isPublicVenuePage()} (D079 / MEM717).
     *
     * Verified commercial venues OR admin-managed commercial venues. Private /
     * unverified / `other` / null-type locations are excluded. The commercial-type
     * set is the single source of truth on {@see VenueType::COMMERCIAL_TYPES};
     * this scope and the disclosure service's in-memory check both read it, so
     * the sitemap, the venue 404 gate, and <x-venue-link> can never drift.
     *
     * Callers that need a resolvable slug (sitemap, route) add ->whereNotNull('slug').
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublicVenuePage(Builder $query): Builder
    {
        $commercial = VenueType::commercialValues();

        // Verified commercial venues OR admin-managed commercial venues.
        // The two branches are OR'd at the top level; each inner closure is
        // self-contained (the outer query is not referenced inside), so the
        // AND/OR precedence this scope depends on is unambiguous.
        return $query->where(fn ($outer) => $outer
            ->where('is_verified', true)
            ->whereIn('venue_type', $commercial)
            ->orWhere(fn ($inner) => $inner
                ->whereNotNull('managed_by')
                ->whereIn('venue_type', $commercial)
            )
        );
    }

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

    // Note: getRouteKeyName() is intentionally NOT overridden to 'slug'.
    // Public venue pages are slug-routed, but they resolve the slug explicitly
    // via VenueDetail::mount()'s Location::where('slug', $slug)->firstOrFail(),
    // and every venues.detail URL is generated with an explicit 'slug' key —
    // neither path uses implicit route-model-binding. Overriding the route key
    // to 'slug' here breaks the Filament admin LocationResource: most locations
    // carry a null slug (only verified/managed commercial venues get one), so
    // the admin table cannot build edit-route URLs and throws a ViewException
    // ("Missing parameter: record"). The default 'id' route key is always
    // present and is what Filament needs. Do not re-add a 'slug' override.

    // ── Slug Generation ────────────────────────────────

    /**
     * Generate a URL-friendly slug from a name.
     *
     * Delegates to {@see SlugService::generate()} — the single shared slug
     * algorithm (User and Location must slug byte-identically; the former
     * byte-identical duplicate of User::generateSlug lives there now).
     */
    public static function generateSlug(string $name): string
    {
        return SlugService::generate($name);
    }

    /**
     * Generate a unique slug for the given name, appending incremental digits on collision.
     *
     * Delegates to {@see SlugService::unique()} — the single shared uniqueness
     * loop (Location fallback: 'venue').
     */
    public static function generateUniqueSlug(string $name, ?string $ignoreId = null): string
    {
        return SlugService::unique(static::query(), 'slug', $name, 'venue', $ignoreId);
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * Calculate approximate distance in km to another point using Haversine formula.
     */
    public function distanceTo(float $lat, float $lng): float
    {
        // Delegates to the single canonical Haversine implementation
        // (ProximityQuery::haversineDistance) so the formula lives in one place;
        // previously this method re-derived it with an identical computation
        // (6371km radius, rounded to 2 dp).
        return ProximityQuery::haversineDistance(
            (float) $this->latitude,
            (float) $this->longitude,
            $lat,
            $lng,
        );
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

        // A location without a public venue-page slug has no URL to emit —
        // route() with a null slug throws, so url() is set only when the
        // public page exists (D172 made every entitySeoData() call reach
        // this generator, including slug-less rows).
        $business = (new LocalBusiness)->name($this->name);

        if (is_string($this->slug) && $this->slug !== '') {
            $business->url(route('venues.detail', [
                'locale' => app()->getLocale(),
                'slug' => $this->slug,
            ]));
        }

        // The schema description is only meaningful when content exists — an
        // empty value would produce a useless description node, and the
        // LocalBusiness::description() type contract is non-nullable anyway.
        if ($this->description) {
            $business->description(Str::limit(strip_tags((string) $this->description), 500));
        }

        // Address: emit a PostalAddress ONLY for verified commercial venues.
        // Verified venues resolve to the Exact address rung for every viewer, so
        // the structured data matches what <x-location-display> renders. A
        // managed-but-unverified commercial venue resolves to the Area rung for
        // strangers ("In your area" — no address shown), so emitting a full
        // PostalAddress here would make the indexed JSON-LD strictly more
        // permissive than the HTML. The VenueDetail route gate already
        // guarantees a commercial type, so is_verified alone is the
        // discriminator (mirrors isVerifiedCommercialVenue).
        if ($this->is_verified) {
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
        }

        $sameAs = [];
        if (($safeUrl = safe_url($this->website_url)) !== null) {
            $sameAs[] = $safeUrl;
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
