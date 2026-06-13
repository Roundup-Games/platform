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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $name
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
 * @property int|null $managed_by
 * @property string|null $venue_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
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
}
