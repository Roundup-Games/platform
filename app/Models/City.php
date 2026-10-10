<?php

namespace App\Models;

use App\Models\Concerns\HasPlatformUuid;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * City hub registry row (D171, evolving 62-04) — one real entity per
 * resolved location cluster (same Str::slug(city), same 3-char geohash
 * region), auto-provisioned by CityRegistryService on location save and
 * linked from locations.city_id.
 *
 * Curation is triage on top of identity: curation_state promotes
 * discovered -> curated on first admin touch (intro/seo/featured/hidden —
 * the City::saving hook), never demotes. featured force-qualifies a hub
 * over both thresholds and feeds the featured rail; hidden removes it
 * from every public surface (enforced inside CityDirectoryService's
 * resolution); intro is translatable hero copy. region_prefix records
 * the cluster's geohash region cell. The snapshot columns are kept
 * fresh by the scheduled cityhubs:recompute command.
 *
 * Rows are provisioned automatically — never hand-created. Slugs are a
 * frozen public URL contract (/cities/{slug}); same-name clusters in
 * other regions get slug-{region}.
 *
 * @property string $id
 * @property string $slug
 * @property string $city
 * @property string|null $country
 * @property string|null $region_prefix
 * @property string|null $intro
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property bool $featured
 * @property bool $hidden
 * @property string $curation_state
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

    use HasPlatformUuid;
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['intro', 'seo_title', 'seo_description'];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id'];

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

    protected static function booted(): void
    {
        // Curation state promotion (D171): a discovered row becomes
        // curated the first time an admin touches a curation field. Never
        // demotes — reverting an edit leaves the row curated, which is the
        // honest audit state (an admin has reviewed it).
        static::saving(function (self $city): void {
            if ($city->curation_state === 'discovered' && $city->isDirty(['intro', 'seo_title', 'seo_description', 'featured', 'hidden'])) {
                $city->curation_state = 'curated';
            }
        });
    }

    /**
     * Locations linked to this registry cluster (D171) — the evidence
     * behind the hub.
     *
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
