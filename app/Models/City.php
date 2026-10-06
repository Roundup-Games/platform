<?php

namespace App\Models;

use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * Admin-curated city hub row (62-04) — the curation layer that sits on top
 * of CityDirectoryService's runtime cluster resolution.
 *
 * One row per curated city slug. featured/hidden steer the hub guard, the
 * sitemap, and the featured-cities rail; intro is translatable hero copy;
 * region_prefix pins ambiguous clusters to one geohash region; the snapshot
 * columns are kept fresh by the scheduled cityhubs:recompute command.
 *
 * @property string $id
 * @property string $slug
 * @property string $city
 * @property string|null $country
 * @property string|null $region_prefix
 * @property string|null $intro
 * @property bool $featured
 * @property bool $hidden
 * @property int|null $upcoming_activity_count
 * @property int|null $verified_venues_count
 * @property Carbon|null $recomputed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['intro'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $city) {
            if (empty($city->id)) {
                $city->id = (string) Str::orderedUuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'hidden' => 'boolean',
            'upcoming_activity_count' => 'integer',
            'verified_venues_count' => 'integer',
            'recomputed_at' => 'datetime',
        ];
    }
}
