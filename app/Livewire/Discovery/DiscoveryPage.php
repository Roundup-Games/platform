<?php

namespace App\Livewire\Discovery;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.public-layout')]
class DiscoveryPage extends Component
{
    use EscapesLikeWildcards;
    use WithPagination;

    // ── Tab / mode filter ──────────────────────────────

    #[Url]
    public string $mode = 'all'; // 'all', 'games', 'campaigns'

    // ── Shared filters ─────────────────────────────────

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $game_system_id = null;

    #[Url]
    public string $experience_level = '';

    #[Url]
    public array $vibe_flags = [];

    #[Url]
    public string $language = '';

    #[Url]
    public ?string $complexity_min = null;

    #[Url]
    public ?string $complexity_max = null;

    #[Url]
    public string $price = '';

    // ── Games-specific filters ─────────────────────────

    #[Url]
    public string $date = '';

    // ── Campaigns-specific filters ─────────────────────

    #[Url]
    public string $recurrence = '';

    // ── Updating hooks ─────────────────────────────────

    public function updatingMode(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingGameSystemId(): void
    {
        $this->resetPage();
    }

    public function updatingExperienceLevel(): void
    {
        $this->resetPage();
    }

    public function updatingVibeFlags(): void
    {
        $this->resetPage();
    }

    public function updatingLanguage(): void
    {
        $this->resetPage();
    }

    public function updatingComplexityMin(): void
    {
        $this->resetPage();
    }

    public function updatingComplexityMax(): void
    {
        $this->resetPage();
    }

    public function updatingPrice(): void
    {
        $this->resetPage();
    }

    public function updatingDate(): void
    {
        $this->resetPage();
    }

    public function updatingRecurrence(): void
    {
        $this->resetPage();
    }

    // ── Actions ────────────────────────────────────────

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
        $this->resetPage();
    }

    public function toggleVibeFlag(string $flag): void
    {
        $index = array_search($flag, $this->vibe_flags, true);
        if ($index !== false) {
            unset($this->vibe_flags[$index]);
            $this->vibe_flags = array_values($this->vibe_flags);
        } else {
            $this->vibe_flags[] = $flag;
        }
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'game_system_id', 'experience_level', 'vibe_flags',
            'language', 'price', 'complexity_min', 'complexity_max',
            'date', 'recurrence',
        ]);
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search
            || $this->game_system_id
            || $this->experience_level
            || !empty($this->vibe_flags)
            || $this->language
            || $this->price
            || $this->complexity_min
            || $this->complexity_max
            || $this->date
            || $this->recurrence;
    }

    // ── Query builders ─────────────────────────────────

    protected function applySharedFilters($query, string $priceColumn): void
    {
        $query->when($this->search, fn ($q) => $q->where(function ($q) {
            $escaped = $this->escapeLikeWildcards($this->search);
            $q->where('name', 'like', "%{$escaped}%")
              ->orWhere('description', 'like', "%{$escaped}%");
        }));

        $query->when($this->game_system_id, fn ($q) => $q->where('game_system_id', $this->game_system_id));
        $query->when($this->experience_level, fn ($q) => $q->where('experience_level', $this->experience_level));

        $query->when(!empty($this->vibe_flags), function ($q) {
            foreach ($this->vibe_flags as $flag) {
                $q->whereJsonContains('vibe_flags', $flag);
            }
        });

        $query->when($this->language, fn ($q) => $q->where('language', $this->language));

        $query->when($this->price === 'free', fn ($q) => $q->where(fn ($q) => $q->where($priceColumn, 0)->orWhereNull($priceColumn)));
        $query->when($this->price === 'paid', fn ($q) => $q->where($priceColumn, '>', 0));

        $query->when($this->complexity_min, fn ($q) => $q->where('complexity', '>=', (float) $this->complexity_min));
        $query->when($this->complexity_max, fn ($q) => $q->where('complexity', '<=', (float) $this->complexity_max));
    }

    protected function buildGamesQuery()
    {
        $user = Auth::user();

        $query = Game::query()
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public');
                if ($user) {
                    $q->orWhere('visibility', 'protected');
                }
            })
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->with(['owner', 'gameSystem', 'campaign'])
            ->withCount('participants');

        $this->applySharedFilters($query, 'price');

        // Games-specific: date range
        $query->when($this->date === 'upcoming', fn ($q) => $q->where('date_time', '>=', now()));
        $query->when($this->date === 'this_week', fn ($q) => $q->whereBetween('date_time', [now()->startOfWeek(), now()->endOfWeek()]));
        $query->when($this->date === 'this_month', fn ($q) => $q->whereBetween('date_time', [now()->startOfMonth(), now()->endOfMonth()]));

        return $query->orderBy('date_time');
    }

    protected function buildCampaignsQuery()
    {
        $user = Auth::user();

        $query = Campaign::query()
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public');
                if ($user) {
                    $q->orWhere('visibility', 'protected');
                }
            })
            ->where('status', 'active')
            ->with(['owner', 'gameSystem'])
            ->withCount('sessions');

        $this->applySharedFilters($query, 'price_per_session');

        // Campaigns-specific: recurrence
        $query->when($this->recurrence, fn ($q) => $q->where('recurrence', $this->recurrence));

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Merge games and campaigns into a unified, paginated collection.
     * Each item gets a ->discoverable_type attribute ('game' or 'campaign')
     * and a ->discoverable_sort_key for consistent ordering.
     */
    protected function getMergedResults(): Paginator
    {
        $perPage = 12;
        $page = (int) request()->get('page', 1);

        $gamesQuery = $this->buildGamesQuery();
        $campaignsQuery = $this->buildCampaignsQuery();

        // Add type discriminator and unified sort key
        $games = $gamesQuery->get()->each(fn ($item) => [
            $item->discoverable_type = 'game',
            $item->discoverable_sort_key = $item->date_time?->timestamp ?? 0,
        ]);

        $campaigns = $campaignsQuery->get()->each(fn ($item) => [
            $item->discoverable_type = 'campaign',
            $item->discoverable_sort_key = $item->created_at?->timestamp ?? 0,
        ]);

        // Merge and sort: games (by date_time) first, then campaigns (by created_at)
        $merged = $games->merge($campaigns)
            ->sortByDesc('discoverable_sort_key')
            ->values();

        $total = $merged->count();
        $items = $merged->slice(($page - 1) * $perPage, $perPage)->values();

        return new Paginator($items, $total, $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /**
     * Get recommended items for logged-in users based on favorite game systems.
     */
    protected function getRecommendations(): ?array
    {
        $user = Auth::user();
        if (!$user) {
            return null;
        }

        $favoriteSystemIds = $user->favoriteGameSystems()->pluck('game_systems.id')->toArray();

        if (empty($favoriteSystemIds)) {
            return null;
        }

        // Games matching favorite systems
        $recommendedGames = Game::query()
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public');
                if ($user) {
                    $q->orWhere('visibility', 'protected');
                }
            })
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->whereIn('game_system_id', $favoriteSystemIds)
            ->with(['owner', 'gameSystem', 'campaign'])
            ->withCount('participants')
            ->orderBy('date_time')
            ->limit(6)
            ->get()
            ->each(fn ($item) => [
                $item->discoverable_type = 'game',
            ]);

        // Campaigns matching favorite systems
        $recommendedCampaigns = Campaign::query()
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public');
                if ($user) {
                    $q->orWhere('visibility', 'protected');
                }
            })
            ->where('status', 'active')
            ->whereIn('game_system_id', $favoriteSystemIds)
            ->with(['owner', 'gameSystem'])
            ->withCount('sessions')
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get()
            ->each(fn ($item) => [
                $item->discoverable_type = 'campaign',
            ]);

        $all = $recommendedGames->merge($recommendedCampaigns);

        if ($all->isEmpty()) {
            return null;
        }

        return $all->all();
    }

    // ── Render ─────────────────────────────────────────

    public function render()
    {
        $results = match ($this->mode) {
            'games' => $this->buildGamesQuery()->paginate(12)->through(fn ($game) => tap($game, fn ($g) => $g->discoverable_type = 'game')),
            'campaigns' => $this->buildCampaignsQuery()->paginate(12)->through(fn ($campaign) => tap($campaign, fn ($c) => $c->discoverable_type = 'campaign')),
            default => $this->getMergedResults(),
        };

        return view('livewire.discovery.discovery-page', [
            'results' => $results,
            'recommendations' => $this->getRecommendations(),
            'gameSystems' => GameSystem::orderBy('name')->get(['id', 'name']),
            'experienceLevels' => ExperienceLevel::cases(),
            'vibeFlagGroups' => VibeFlag::grouped(),
            'languages' => ContentLanguage::cases(),
            'recurrenceOptions' => ['weekly', 'bi-weekly', 'monthly'],
        ]);
    }
}
