<?php

namespace App\Livewire\Components;

use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Game;
use App\Services\ProximityQuery;
use App\Traits\HasGuestLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Nearby sessions component with location gate.
 *
 * Flow:
 *   1. Renders location gate (geolocation button + manual city input) when no coordinates.
 *   2. When location arrives (via HasGuestLocation trait), queries ProximityQuery for
 *      nearby public game sessions and campaigns within the configured radius.
 *   3. Results are sorted by BGG rank (nulls last) then date.
 *   4. Renders proximity-sorted cards with distance badges, game system info, and join CTA.
 *   5. When no sessions in primary radius, falls back to wider radius and shows organizer CTA.
 *
 * Dispatches:
 *   location-gate-converted — when location is received and results rendered { source, resultCount }
 */
class NearbySessions extends Component
{
    use HasGuestLocation;

    /** Default search radius in km */
    public float $radius = 10;

    /** Wider fallback radius when primary returns nothing */
    public float $fallbackRadius = 50;

    /** Maximum number of sessions to show */
    public int $limit = 4;

    /** Whether to include campaigns in results */
    public bool $includeCampaigns = true;

    /** Manual city search query */
    public ?string $cityQuery = null;

    /** Whether we've attempted the wider fallback */
    public bool $triedFallback = false;

    /** Whether results came from the fallback radius */
    public bool $usingFallbackRadius = false;

    /** Cached session results to avoid re-querying */
    protected ?Collection $cachedSessions = null;

    public function mount(float $radius = 10, int $limit = 4, bool $includeCampaigns = true): void
    {
        $this->radius = $radius;
        $this->limit = $limit;
        $this->includeCampaigns = $includeCampaigns;
    }

    /**
     * When location is received, log conversion event for analytics.
     * Overrides the trait method to add logging while keeping the same #[On] listener.
     */
    #[On('guest-location-updated')]
    public function onGuestLocationUpdated(float $lat, float $lng, string $source = 'unknown'): void
    {
        $this->guestLat = $lat;
        $this->guestLng = $lng;
        $this->guestLocationSource = $source;

        // Reset fallback state and cache when new location arrives
        $this->triedFallback = false;
        $this->usingFallbackRadius = false;
        $this->cachedSessions = null;

        $count = $this->getSessions()->count();

        Log::info('Location gate converted', [
            'source' => $source,
            'result_count' => $count,
            'is_fallback' => $this->usingFallbackRadius,
            'radius_km' => $this->usingFallbackRadius ? $this->fallbackRadius : $this->radius,
        ]);
    }

    /**
     * Handle manual city search submission.
     *
     * Geocodes the city query and updates guest location coordinates.
     */
    public function searchCity(): void
    {
        $this->validate([
            'cityQuery' => 'required|string|min:2|max:200',
        ]);

        try {
            $geocodingService = app(\App\Services\GeocodingService::class);
            $results = $geocodingService->geocode($this->cityQuery);

            if (!empty($results)) {
                $first = $results;
                $this->guestLat = (float) $first['lat'];
                $this->guestLng = (float) $first['lng'];
                $this->guestLocationSource = 'manual';

                $this->triedFallback = false;
                $this->usingFallbackRadius = false;

                // Persist to localStorage via JS
                $this->js(<<<JS
                    if (window.GuestLocation) {
                        window.GuestLocation.setGuestLocation({$this->guestLat}, {$this->guestLng}, 'manual');
                    }
                JS);
            }
        } catch (\Throwable $e) {
            Log::warning('City geocoding failed', [
                'query' => $this->cityQuery,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Trigger browser geolocation request.
     */
    public function locateMe(): void
    {
        $this->js(<<<'JS'
            if (window.GuestLocation) {
                window.GuestLocation.locateAndDispatch('nearby-sessions');
            }
        JS);
    }

    /**
     * Get nearby sessions, sorted by BGG rank then date.
     *
     * @return Collection<int, object{entity: Game|Campaign, location: \App\Models\Location|null, distance_km: float, game_system: \App\Models\GameSystem|null, participant_count: int, type: string}>
     */
    #[Computed]
    public function getSessions(): Collection
    {
        if ($this->cachedSessions !== null) {
            return $this->cachedSessions;
        }

        if (!$this->hasGuestLocation()) {
            return collect();
        }

        $proximity = app(ProximityQuery::class);
        $radius = $this->radius;

        // Query games (sessions)
        $gameResults = $proximity->nearby(
            $this->guestLat,
            $this->guestLng,
            $radius,
            'game',
            ['limit' => $this->limit * 2, 'status_filter' => true, 'visibility' => [Visibility::Public->value]],
        );

        // Query campaigns via their sessions if enabled
        $campaignResults = collect();
        if ($this->includeCampaigns) {
            $campaignResults = $this->getNearbyCampaigns($proximity, $radius);
        }

        // Combine and format
        $all = $gameResults->map(function ($result) {
            $game = $result->entity;
            return (object) [
                'entity' => $game,
                'location' => $result->location,
                'distance_km' => $result->distance_km,
                'game_system' => $game->gameSystem,
                'participant_count' => $game->participants()->count(),
                'type' => 'session',
            ];
        })->merge($campaignResults->map(function ($result) {
            $campaign = $result->entity;
            return (object) [
                'entity' => $campaign,
                'location' => $result->location,
                'distance_km' => $result->distance_km,
                'game_system' => $campaign->gameSystem,
                'participant_count' => $campaign->participants()->count(),
                'type' => 'campaign',
            ];
        }));

        // Sort by BGG rank (nulls last), then by distance
        $sorted = $all->sortBy(function ($item) {
            $rank = $item->game_system?->bgg_rank;
            return [$rank === null ? PHP_INT_MAX : $rank, $item->distance_km];
        })->values();

        // If primary radius returns nothing, try fallback
        if ($sorted->isEmpty() && !$this->triedFallback) {
            $this->triedFallback = true;
            $this->usingFallbackRadius = true;

            // Re-query with fallback radius
            $fallbackResults = $proximity->nearby(
                $this->guestLat,
                $this->guestLng,
                $this->fallbackRadius,
                'game',
                ['limit' => $this->limit, 'status_filter' => true, 'visibility' => [Visibility::Public->value]],
            );

            $sorted = $fallbackResults->map(function ($result) {
                $game = $result->entity;
                return (object) [
                    'entity' => $game,
                    'location' => $result->location,
                    'distance_km' => $result->distance_km,
                    'game_system' => $game->gameSystem,
                    'participant_count' => $game->participants()->count(),
                    'type' => 'session',
                ];
            })->sortBy(function ($item) {
                $rank = $item->game_system?->bgg_rank;
                return [$rank === null ? PHP_INT_MAX : $rank, $item->distance_km];
            })->values();
        }

        return $this->cachedSessions = $sorted->take($this->limit);
    }

    /**
     * Whether there are no sessions in any radius.
     */
    #[Computed]
    public function getIsEmpty(): bool
    {
        return $this->hasGuestLocation() && $this->getSessions()->isEmpty();
    }

    /**
     * Format distance for display.
     */
    public function formatDistance(float $km): string
    {
        if ($km < 1) {
            return __('discovery.content_meters_m_away', ['meters' => round($km * 1000)]);
        }
        return __('discovery.content_km_km_away', ['km' => number_format($km, 1)]);
    }

    /**
     * Get nearby campaigns through their scheduled sessions' locations.
     */
    protected function getNearbyCampaigns(ProximityQuery $proximity, float $radius): Collection
    {
        // Get public scheduled games that belong to campaigns within radius
        $gameResults = $proximity->nearby(
            $this->guestLat,
            $this->guestLng,
            $radius,
            'game',
            ['limit' => 50, 'status_filter' => true, 'visibility' => [Visibility::Public->value]],
        );

        // Group by campaign_id to deduplicate
        $campaignIds = $gameResults
            ->filter(fn ($r) => $r->entity->campaign_id !== null)
            ->groupBy('entity.campaign_id')
            ->map(fn ($group) => $group->sortBy('distance_km')->first());

        if ($campaignIds->isEmpty()) {
            return collect();
        }

        // Load campaigns and map with distance
        $campaigns = Campaign::whereIn('id', $campaignIds->keys())
            ->where('visibility', 'public')
            ->where('status', '!=', 'completed')
            ->get();

        return $campaigns->map(function ($campaign) use ($campaignIds) {
            $gameResult = $campaignIds->get($campaign->id);
            return (object) [
                'entity' => $campaign,
                'location' => $gameResult?->location,
                'distance_km' => $gameResult?->distance_km ?? 0,
            ];
        });
    }

    public function render()
    {
        return view('livewire.components.nearby-sessions');
    }
}
