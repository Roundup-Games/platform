<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\CityAlias;
use App\Support\CityNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Debounced city-input suggestions (D174): guides free-text intake toward
 * existing hubs without gating it. Ranking is confidence-ordered:
 *
 *   1. alias rules — confirmed merges (normalized exact): "munchin"
 *      surfaces the München hub it was merged into
 *   2. normalized exact — "Muenchen" hits "München" (ue/oe/ae folding)
 *   3. trigram similarity >= 0.35 — exonyms and typos ("Munich");
 *      suggestions only — the write path never auto-merges on trigram
 *
 * Mirrors the api.geocode middleware posture (throttle:api group).
 */
class CitiesSuggestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $rawQuery = $request->input('q', '');
        $query = trim(is_string($rawQuery) ? $rawQuery : '');

        if (mb_strlen($query) < 3) {
            return response()->json(['suggestions' => []]);
        }

        $key = CityNormalizer::key($query);
        $matched = collect();

        if ($key !== '') {
            // Tier 1 + 2: alias rules and normalized-exact slug matches.
            $aliasCityIds = CityAlias::query()
                ->where('normalized_key', $key)
                ->pluck('city_id');

            $matched = City::query()
                ->where(function ($q) use ($key, $aliasCityIds): void {
                    $q->whereKey($aliasCityIds->all())->orWhereIn('slug', [$key]);
                })
                ->withCount('locations')
                ->limit(10)
                ->get()
                ->keyBy->getKey();
        }

        // Tier 3: trigram, suggestions only.
        if ($matched->count() < 8) {
            City::query()
                ->select('cities.*')
                ->selectRaw('similarity(city, ?) AS sim', [$query])
                ->whereRaw('city % ?', [$query])
                ->whereRaw('similarity(city, ?) >= 0.35', [$query])
                ->when($matched->isNotEmpty(), fn ($q) => $q->whereKeyNot($matched->keys()->all()))
                ->withCount('locations')
                ->orderByDesc('sim')
                ->limit(8 - $matched->count())
                ->get()
                ->each(function (City $city) use ($matched): void {
                    $key = $city->getKey();
                    if (is_string($key)) {
                        $matched->put($key, $city);
                    }
                });
        }

        return response()->json([
            'suggestions' => $matched->values()->map(fn (City $city): array => [
                'city' => $city->city,
                'slug' => $city->slug,
                'hub_url' => '/cities/'.$city->slug,
                'locations' => $city->locations_count,
                'curated' => $city->curation_state === 'curated',
            ])->all(),
        ]);
    }
}
