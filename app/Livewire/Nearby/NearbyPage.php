<?php

namespace App\Livewire\Nearby;

use App\Models\Campaign;
use App\Models\Game;
use App\Services\ProximityQuery;
use App\Traits\HasGuestLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Full-page nearby sessions view at /{locale}/near.
 *
 * Features:
 *   - Radius toggle (10km, 25km, 50km) stored in URL query param.
 *   - Sessions grouped by time: This Week, Coming Up, Ongoing Campaigns.
 *   - Full-page location CTA when no location stored.
 *   - Organizer recruitment with wider-radius fallback when no sessions found.
 *   - Reuses session-card partial from NearbySessions component.
 */
#[Layout('components.public-layout')]
class NearbyPage extends Component
{
    use HasGuestLocation;

    /** @var float Search radius in km — persisted in URL query string */
    #[Url(as: 'radius')]
    public float $radius = 10;

    /** @var string|null Manual city search query */
    public ?string $cityQuery = null;

    /** @var bool Whether we've attempted the wider fallback */
    public bool $triedFallback = false;

    /** @var bool Whether results came from a wider fallback radius */
    public bool $usingFallbackRadius = false;

    /** @var Collection|null Cached session results */
    protected ?Collection $cachedSessions = null;

    /** Available radius options */
    public const RADIUS_OPTIONS = [10, 25, 50];

    /** Fallback radius when no results found in selected radius */
    private const FALLBACK_RADIUS = 100;

    public function mount(): void
    {
        // Clamp radius to valid options
        if (!in_array($this->radius, self::RADIUS_OPTIONS, false)) {
            $this->radius = 10;
        }
    }

    /**
     * When location is received, log conversion event and reset cache.
     */
    #[On('guest-location-updated')]
    public function onGuestLocationUpdated(float $lat, float $lng, string $source = 'unknown'): void
    {
        $this->guestLat = $lat;
        $this->guestLng = $lng;
        $this->guestLocationSource = $source;

        $this->triedFallback = false;
        $this->usingFallbackRadius = false;
        $this->cachedSessions = null;

        $count = $this->getSessions()->count();

        Log::info('Nearby page: location gate converted', [
            'source' => $source,
            'result_count' => $count,
            'is_fallback' => $this->usingFallbackRadius,
            'radius_km' => $this->usingFallbackRadius ? self::FALLBACK_RADIUS : $this->radius,
        ]);
    }

    /**
     * Update the search radius and clear cached results.
     */
    public function setRadius(float $radius): void
    {
        if (!in_array($radius, self::RADIUS_OPTIONS, false)) {
            return;
        }
        $this->radius = $radius;
        $this->triedFallback = false;
        $this->usingFallbackRadius = false;
        $this->cachedSessions = null;
    }

    /**
     * Handle manual city search submission.
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

                $this->js(<<<JS
                    if (window.GuestLocation) {
                        window.GuestLocation.setGuestLocation({$this->guestLat}, {$this->guestLng}, 'manual');
                    }
                JS);
            }
        } catch (\Throwable $e) {
            Log::warning('Nearby page: city geocoding failed', [
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
                window.GuestLocation.locateAndDispatch('nearby-page');
            }
        JS);
    }

    /**
     * Get nearby sessions within current radius.
     *
     * @return Collection<int, object{entity: Game|Campaign, location: \App\Models\Location|null, distance_km: float, game_system: \App\Models\GameSystem|null, participant_count: int, type: string}>
     */
    public function getSessions(): Collection
    {
        if ($this->cachedSessions !== null) {
            return $this->cachedSessions;
        }

        if (!$this->hasGuestLocation()) {
            return collect();
        }

        $proximity = app(ProximityQuery::class);

        // Query public game sessions
        $gameResults = $proximity->nearby(
            $this->guestLat,
            $this->guestLng,
            $this->radius,
            'game',
            ['limit' => 100, 'status_filter' => true],
        );

        $gameResults = $gameResults->filter(fn ($r) => $r->entity->visibility === 'public');

        // Query campaigns via their sessions
        $campaignResults = $this->getNearbyCampaigns($proximity, $this->radius);

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

        // Sort by distance
        $sorted = $all->sortBy('distance_km')->values();

        // Fallback to wider radius when empty
        if ($sorted->isEmpty() && !$this->triedFallback) {
            $this->triedFallback = true;
            $this->usingFallbackRadius = true;

            $fallbackResults = $proximity->nearby(
                $this->guestLat,
                $this->guestLng,
                self::FALLBACK_RADIUS,
                'game',
                ['limit' => 50, 'status_filter' => true],
            );

            $fallbackResults = $fallbackResults->filter(fn ($r) => $r->entity->visibility === 'public');

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
            })->sortBy('distance_km')->values();
        }

        return $this->cachedSessions = $sorted;
    }

    /**
     * Group sessions by time category for display.
     *
     * Returns an array of groups: 'this_week', 'coming_up', 'campaigns'.
     * Each group has 'label' and 'items'.
     */
    public function getGroupedSessions(): array
    {
        $sessions = $this->getSessions();

        $thisWeek = $sessions->filter(function ($item) {
            if ($item->type === 'campaign') {
                return false;
            }
            $dt = $item->entity->date_time;
            return $dt && $dt->gte(now()->startOfWeek()) && $dt->lte(now()->endOfWeek());
        })->values();

        $comingUp = $sessions->filter(function ($item) {
            if ($item->type === 'campaign') {
                return false;
            }
            $dt = $item->entity->date_time;
            return $dt && $dt->gt(now()->endOfWeek());
        })->values();

        $campaigns = $sessions->filter(fn ($item) => $item->type === 'campaign')->values();

        return [
            [
                'key' => 'this_week',
                'label' => __('This Week'),
                'items' => $thisWeek,
            ],
            [
                'key' => 'coming_up',
                'label' => __('Coming Up'),
                'items' => $comingUp,
            ],
            [
                'key' => 'campaigns',
                'label' => __('Ongoing Campaigns'),
                'items' => $campaigns,
            ],
        ];
    }

    /**
     * Whether there are no sessions in any radius.
     */
    public function getIsEmptyProperty(): bool
    {
        return $this->hasGuestLocation() && $this->getSessions()->isEmpty();
    }

    /**
     * Format distance for display.
     */
    public function formatDistance(float $km): string
    {
        if ($km < 1) {
            return __(':meters m away', ['meters' => round($km * 1000)]);
        }
        return __(':km km away', ['km' => number_format($km, 1)]);
    }

    /**
     * Get nearby campaigns through their scheduled sessions' locations.
     */
    protected function getNearbyCampaigns(ProximityQuery $proximity, float $radius): Collection
    {
        $gameResults = $proximity->nearby(
            $this->guestLat,
            $this->guestLng,
            $radius,
            'game',
            ['limit' => 100, 'status_filter' => true],
        );

        $campaignIds = $gameResults
            ->filter(fn ($r) => $r->entity->campaign_id !== null && $r->entity->visibility === 'public')
            ->groupBy('entity.campaign_id')
            ->map(fn ($group) => $group->sortBy('distance_km')->first());

        if ($campaignIds->isEmpty()) {
            return collect();
        }

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
        return view('livewire.nearby.nearby-page');
    }
}
