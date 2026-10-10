<?php

use App\Models\City;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * City registry: city hubs become real entities, and locations reference
 * them by FK instead of clustering on raw city strings at read time (D171).
 *
 * Before: a city cluster existed only as read-time PHP string matching
 * over locations.city (Str::slug cannot run in SQL), with the cities
 * table as a manual curation overlay — empty until an admin typed a row,
 * and with no reachable creation UI in Filament v5.
 *
 * After: every resolved cluster (same Str::slug(city), same 3-char
 * geohash region) gets a registry row auto-provisioned here and on every
 * location save thereafter (CityRegistryService). locations.city_id is
 * the canonical link, ON DELETE RESTRICT — a hub with attached venues
 * must never silently vanish — while locations.city survives as geocoder
 * provenance. Curation becomes triage of discovered rows, never row
 * creation, so admin input can neither conjure nor corrupt a cluster.
 *
 * Same-name clusters in different regions (the old "ambiguous" 404)
 * become two addressable hubs: the larger cluster keeps the bare slug,
 * the other gets slug-{region}. Deterministic at provisioning; slugs are
 * frozen once created (public URL contract, same discipline as D170).
 *
 * Backfill is self-contained (no runtime service calls) so a later
 * refactor cannot change what this migration does — mirroring the
 * frozen-list rule of 2026_10_09_140000.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Hygiene: trim raw geocoder strings before clustering so
        // "Berlin " and "Berlin" land in one cluster by identity.
        DB::table('locations')->whereNotNull('city')->whereRaw('city IS DISTINCT FROM btrim(city)')->update([
            'city' => DB::raw('btrim(city)'),
        ]);
        DB::table('locations')->whereNotNull('country')->whereRaw('country IS DISTINCT FROM btrim(country)')->update([
            'country' => DB::raw('btrim(country)'),
        ]);

        Schema::table('cities', function (Blueprint $table): void {
            $table->string('curation_state', 20)->default('discovered')->index();
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->foreignUuid('city_id')->nullable()->after('country')
                ->constrained('cities')->restrictOnDelete();
            $table->index('city_id');
        });

        $this->backfillRegistry();
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropIndex(['city_id']);
            $table->dropConstrainedForeignId('city_id');
        });

        Schema::table('cities', function (Blueprint $table): void {
            $table->dropIndex(['curation_state']);
            $table->dropColumn('curation_state');
        });
    }

    /**
     * Provision one registry row per (slug, region) cluster and link its
     * locations. Mirrors CityRegistryService::provisionFor — deliberately
     * duplicated so the migration never depends on runtime code.
     */
    private function backfillRegistry(): void
    {
        $rows = DB::table('locations')
            ->whereNotNull('city')
            ->whereNotNull('geohash_4')
            ->whereNotNull('country')
            ->selectRaw('city, country, left(geohash_4, 3) as region, count(*) as n')
            ->groupBy('city', 'country', DB::raw('left(geohash_4, 3)'))
            ->get();

        // slug => region => ['variants' => [city strings], 'n' => total]
        $clusters = [];

        foreach ($rows as $row) {
            $slug = Str::slug((string) $row->city);
            if ($slug === '') {
                continue;
            }

            $cluster = $clusters[$slug][$row->region] ?? ['variants' => [], 'n' => 0, 'country' => null];
            $cluster['variants'][] = (string) $row->city;
            $cluster['n'] += (int) $row->n;
            $cluster['country'] ??= (string) $row->country;
            $clusters[$slug][$row->region] = $cluster;
        }

        foreach ($clusters as $slug => $regions) {
            // Deterministic order: the largest cluster keeps the bare slug.
            uksort($regions, fn (string $a, string $b): int => [$regions[$b]['n'], $a] <=> [$regions[$a]['n'], $b]);

            $isPrimary = true;

            foreach ($regions as $region => $cluster) {
                $finalSlug = $isPrimary ? $slug : "{$slug}-{$region}";
                $isPrimary = false;

                $existing = City::query()->where('slug', $finalSlug)->first();

                if ($existing === null) {
                    $city = City::create([
                        'slug' => $finalSlug,
                        'city' => $cluster['variants'][0],
                        'country' => $cluster['country'],
                        'region_prefix' => $region,
                        'curation_state' => 'discovered',
                    ]);
                    $cityId = $city->id;
                } else {
                    // A pre-existing curated row owns this slug: keep its
                    // curation, stamp only the cluster identity it lacked.
                    City::query()->whereKey($existing->getKey())->update(array_filter([
                        'region_prefix' => $existing->region_prefix ?? $region,
                        'country' => $existing->country ?? $cluster['country'],
                        'city' => $existing->city,
                        'curation_state' => $this->stateForExistingRow($existing),
                    ], fn ($value) => $value !== null));

                    $cityId = $existing->id;
                }

                DB::table('locations')
                    ->whereNull('city_id')
                    ->whereIn('city', $cluster['variants'])
                    ->whereNotNull('geohash_4')
                    ->whereRaw('left(geohash_4, 3) = ?', [$region])
                    ->update(['city_id' => $cityId]);
            }
        }
    }

    private function stateForExistingRow(City $city): string
    {
        $alreadyCurated = ($city->intro !== null && $city->getTranslations('intro') !== [])
            || $city->featured === true
            || $city->hidden === true;

        return $alreadyCurated ? 'curated' : 'discovered';
    }
};
