<?php

namespace App\Livewire\Events;

use App\Dto\CitySummary;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Services\CityDirectoryService;
use App\Traits\EscapesLikeWildcards;
use App\Traits\FoldsCanonicalToCityHub;
use App\Traits\QueriesTranslatableColumns;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RalphJSmit\Laravel\SEO\Support\SEOData;

#[Layout('components.public-layout')]
class EventListing extends Component
{
    use EscapesLikeWildcards;
    use FoldsCanonicalToCityHub;
    use QueriesTranslatableColumns;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $date = '';

    /**
     * URL-addressable city slug (?city=koeln) — the discovery pages' city
     * awareness (M062 62-03) applied to events: the slug only narrows the
     * listing when CityDirectoryService resolves it to a qualifying city
     * cluster, and any qualifying ?city= folds the canonical to that
     * city's hub (see FoldsCanonicalToCityHub).
     */
    #[Url]
    public ?string $city = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingDate(): void
    {
        $this->resetPage();
    }

    public function updatingCity(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'status', 'date', 'city']);
        $this->resetPage();
    }

    public function render(): View
    {
        // ?city= awareness, matching the discovery pages: the filter is a
        // pure additive constraint that only applies when the slug resolves
        // to a qualifying city cluster (CityDirectoryService, the single
        // authority — MEM1023). Unknown / ambiguous / below-threshold
        // slugs are inert: unfiltered results, no canonical fold.
        $citySummary = $this->resolveCityFilter();
        // City narrowing payload resolved up front — the when() closure below
        // carries a plain array (the truthy condition guarantees the summary
        // resolved, so the empty fallback never reaches the query).
        $cityLocationIds = $citySummary === null ? [] : $citySummary->locationIds;

        // A qualifying ?city= folds the canonical to the city's hub — all
        // other params dropped (MEM997); null keeps the transformer's
        // path-derived defaults.
        [$cityCanonical, $cityAlternates] = $this->citySeo();

        seo()->for(new SEOData(
            title: __('events.seo_title'),
            description: __('events.seo_description'),
            canonical_url: $cityCanonical,
            alternates: $cityAlternates,
        ));

        $query = Event::query()
            // M063/S06/T03: the grid renders the shared <x-event-card>, whose
            // derived-offering line reads Event::offeredSystems(). Eager-loading
            // this shape — the one EventCardTest's N+1 guard pins — keeps every
            // card's read zero-query instead of a cold per-event aggregation
            // per card on a 12-event page.
            ->with('tables.gameSystems')
            ->where('is_public', true)
            ->whereIn('status', [
                EventStatus::Published,
                EventStatus::RegistrationOpen,
                EventStatus::RegistrationClosed,
                EventStatus::InProgress,
            ])
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                // Translatable columns (name) use QueriesTranslatableColumns which
                // handles escaping internally — pass raw $this->search.
                // Non-translatable columns (city, venue_name) use the traditional
                // escapeLikeWildcards + likeOperator pattern.
                $escaped = $this->escapeLikeWildcards($this->search);
                $this->whereTranslatableLike($q, 'name', $this->search);
                $q->orWhere('city', $this->likeOperator(), "%{$escaped}%")
                    ->orWhere('venue_name', $this->likeOperator(), "%{$escaped}%");
            }))
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->date === 'upcoming', fn ($q) => $q->where('start_date', '>=', now()))
            ->when($this->date === 'past', fn ($q) => $q->where('end_date', '<', now()))
            ->when($this->date === 'this_week', fn ($q) => $q->whereBetween('start_date', [now()->startOfWeek(), now()->endOfWeek()]))
            ->when($this->date === 'this_month', fn ($q) => $q->whereBetween('start_date', [now()->startOfMonth(), now()->endOfMonth()]))
            // Nullsafe: the closure only runs when the summary resolved non-null
            // (when()'s condition), so the fallback never fires at runtime.
            ->when($citySummary, fn ($q) => $q->whereIn('location_id', $cityLocationIds))

            // Featured events first, then by start_date
            ->orderByDesc('is_featured')
            ->orderBy('start_date');

        $events = $query->paginate(12);

        return view('livewire.events.event-listing', [
            'events' => $events,
        ]);
    }

    /**
     * Resolve ?city= to its qualifying cluster summary, or null when the
     * filter is inert (absent, unknown, or non-qualifying). Resolution is
     * cached per city (900s), so citySeo()'s re-resolve in render() is a
     * cache hit after this first resolve — same shape as the discovery
     * pages' DiscoveryQueryService::resolveCityFilter.
     */
    private function resolveCityFilter(): ?CitySummary
    {
        if (! filled($this->city)) {
            return null;
        }

        $directory = app(CityDirectoryService::class);
        $summary = $directory->resolveCity($this->city);

        return ($summary !== null && $directory->isQualifying($summary)) ? $summary : null;
    }
}
