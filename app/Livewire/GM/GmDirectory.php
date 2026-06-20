<?php

namespace App\Livewire\GM;

use App\Enums\GmProficiency;
use App\Models\GMProfile;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RalphJSmit\Laravel\SEO\Support\SEOData;

#[Layout('components.public-layout')]
class GmDirectory extends Component
{
    use EscapesLikeWildcards;
    use WithPagination;

    // ── Filters ─────────────────────────────────────────

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?string $specialization = null;

    #[Url]
    public ?string $game_system_id = null;

    #[Url]
    public ?int $min_rating = null;

    #[Url(as: 'sort')]
    public string $sortBy = 'highest_rated';

    // ── Load-more (display count) ────────────────────────

    public int $displayCount = 12;

    /**
     * Upper bound on displayCount, regardless of client-supplied value.
     *
     * displayCount is a Livewire public property, so a client can set it via
     * the wire payload. Clamp before paginate() to bound query cost — a large
     * value would otherwise pull an unbounded result set. 48 = 4× the 12/page
     * "load more" increment, generous for normal browsing.
     */
    protected const MAX_DISPLAY_COUNT = 48;

    // ── Updating hooks (reset display on filter change) ─

    public function updatingSearch(): void
    {
        $this->displayCount = 12;
    }

    public function updatingMinRating(): void
    {
        $this->displayCount = 12;
    }

    public function updatingSortBy(): void
    {
        $this->displayCount = 12;
    }

    // ── Actions ─────────────────────────────────────────

    public function toggleSpecialization(string $value): void
    {
        $this->specialization = $this->specialization === $value ? null : $value;
        $this->displayCount = 12;
    }

    #[On('value-updated')]
    public function onGameSystemUpdated(mixed $value): void
    {
        $this->game_system_id = is_string($value) ? $value : null;
        $this->displayCount = 12;
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'specialization', 'game_system_id', 'min_rating', 'sortBy']);
        $this->sortBy = 'highest_rated';
        $this->displayCount = 12;
    }

    public function loadMore(): void
    {
        // Cap on increment: never let loadMore() itself push past the cap, so
        // the button naturally stops growing the count at MAX_DISPLAY_COUNT.
        $next = $this->displayCount + 12;
        $this->displayCount = min($next, self::MAX_DISPLAY_COUNT);
    }

    /**
     * displayCount clamped to MAX_DISPLAY_COUNT for safe use in paginate().
     *
     * Guards against a client setting displayCount to an arbitrary value via
     * the wire payload; paginate() would otherwise honor it unbounded.
     */
    private function effectiveDisplayCount(): int
    {
        return max(1, min($this->displayCount, self::MAX_DISPLAY_COUNT));
    }

    public function hasActiveFilters(): bool
    {
        return $this->search
            || $this->specialization
            || $this->game_system_id
            || $this->min_rating;
    }

    // ── Render ──────────────────────────────────────────

    public function render(): View
    {
        seo(new SEOData(
            title: __('gms.seo_title_gm_directory'),
            description: __('gms.seo_description_gm_directory'),
        ));

        $query = GMProfile::where('is_active', true)
            ->whereHas('user', fn ($q) => $q->whereNull('anonymized_at'))
            ->with('user')
            ->withCount('reviews');

        // Search by GM name (via user relationship)
        if ($this->search) {
            $escaped = $this->escapeLikeWildcards($this->search);
            $query->whereHas('user', function ($q) use ($escaped) {
                $q->where('name', $this->likeOperator(), "%{$escaped}%");
            });
        }

        // Filter by specialization
        if ($this->specialization) {
            $query->whereJsonContains('specializations', $this->specialization);
        }

        // Filter by game system (GM has run games with this system)
        if ($this->game_system_id) {
            $query->whereHas('user.ownedGames', function ($q) {
                $q->where('game_system_id', $this->game_system_id);
            });
        }

        // Filter by minimum rating
        if ($this->min_rating) {
            $query->where('average_rating', '>=', $this->min_rating);
        }

        // Sort
        match ($this->sortBy) {
            'most_reviewed' => $query->orderBy('review_count', 'desc'),
            'newest' => $query->orderBy('created_at', 'desc'),
            default => $query->orderByRaw('COALESCE(average_rating, 0) DESC'),
        };

        $results = $query->paginate($this->effectiveDisplayCount());

        // Load top proficiencies for each GM
        $results->through(function ($gmProfile) {
            $gmProfile->top_proficiencies = $gmProfile->topProficiencies(3);

            return $gmProfile;
        });

        return view('livewire.gm.gm-directory', [
            'results' => $results,
            'proficiencies' => GmProficiency::cases(),
        ]);
    }
}
