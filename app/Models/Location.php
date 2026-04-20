<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'address',
        'city',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'place_id',
        'source',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'metadata' => 'array',
        ];
    }

    // ── Relationships ──────────────────────────────────

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
     */
    public function scopeWithinBounds($query, float $minLat, float $maxLat, float $minLng, float $maxLng)
    {
        return $query->whereBetween('latitude', [$minLat, $maxLat])
            ->whereBetween('longitude', [$minLng, $maxLng]);
    }

    /**
     * Find or create a location by place_id for deduplication.
     */
    public static function findOrCreateByPlaceId(string $placeId, array $attributes = []): ?self
    {
        return static::firstOrCreate(
            ['place_id' => $placeId],
            $attributes
        );
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
