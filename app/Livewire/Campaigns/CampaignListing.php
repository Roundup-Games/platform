<?php

namespace App\Livewire\Campaigns;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Models\Campaign;
use App\Traits\EscapesLikeWildcards;
use App\Traits\QueriesTranslatableColumns;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.public-layout')]
class CampaignListing extends Component
{
    use EscapesLikeWildcards;
    use QueriesTranslatableColumns;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?string $game_system_id = null;

    #[Url]
    public string $experience_level = '';

    #[Url]
    public array $vibe_flags = [];

    #[Url]
    public string $language = '';

    #[Url]
    public string $recurrence = '';

    #[Url]
    public string $price = '';

    #[Url]
    public ?string $complexity_min = null;

    #[Url]
    public ?string $complexity_max = null;

    public function mount(): void
    {
        $user = Auth::user();
        if (!$this->language) {
            $this->language = ($user && $user->preferred_language)
                ? $user->preferred_language->value
                : app()->getLocale();
        }
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

    public function updatingRecurrence(): void
    {
        $this->resetPage();
    }

    public function updatingPrice(): void
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

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'game_system_id', 'experience_level', 'vibe_flags',
            'language', 'recurrence', 'price', 'complexity_min', 'complexity_max',
        ]);
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

    public function hasActiveFilters(): bool
    {
        return $this->search
            || $this->game_system_id
            || $this->experience_level
            || !empty($this->vibe_flags)
            || ($this->language && $this->language !== app()->getLocale())
            || $this->recurrence
            || $this->price
            || $this->complexity_min
            || $this->complexity_max;
    }

    public function render()
    {
        $user = Auth::user();

        $query = Campaign::query()
            // Visibility scoping: public for everyone, protected for friends/teammates/participants, private excluded
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public');
                if ($user) {
                    $q->orWhere(function ($q) use ($user) {
                        $q->where('visibility', 'protected')
                            ->where(function ($q) use ($user) {
                                $allowedOwnerIds = $user->getAllowedOwnerIdsForProtectedContent();
                                $q->whereIn('owner_id', $allowedOwnerIds)
                                    ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $user->id));
                            });
                    });
                }
            })
            // Only active campaigns
            ->where('status', 'active')
            ->with(['owner', 'gameSystem'])
            ->withCount('sessions');

        // Search by name/description
        $query->when($this->search, fn ($q) => $q->where(function ($q) {
            $this->whereTranslatableLike($q, 'name', $this->search);
            $this->orWhereTranslatableLike($q, 'description', $this->search);
        }));

        // Game system filter
        $query->when($this->game_system_id, fn ($q) => $q->where('game_system_id', $this->game_system_id));

        // Experience level filter
        $query->when($this->experience_level, fn ($q) => $q->where('experience_level', $this->experience_level));

        // Vibe flags filter (JSON containment — campaign must have ALL selected flags)
        $query->when(!empty($this->vibe_flags), function ($q) {
            foreach ($this->vibe_flags as $flag) {
                $q->whereJsonContains('vibe_flags', $flag);
            }
        });

        // Language filter
        $query->when($this->language, fn ($q) => $q->where('language', $this->language));

        // Recurrence filter
        $query->when($this->recurrence, fn ($q) => $q->where('recurrence', $this->recurrence));

        // Price filter
        $query->when($this->price === 'free', fn ($q) => $q->where(fn ($q) => $q->where('price_per_session', 0)->orWhereNull('price_per_session')));
        $query->when($this->price === 'paid', fn ($q) => $q->where('price_per_session', '>', 0));

        // Complexity range
        $query->when($this->complexity_min, fn ($q) => $q->where('complexity', '>=', (float) $this->complexity_min));
        $query->when($this->complexity_max, fn ($q) => $q->where('complexity', '<=', (float) $this->complexity_max));

        $campaigns = $query->orderBy('created_at', 'desc')->paginate(12);

        return view('livewire.campaigns.campaign-listing', [
            'campaigns' => $campaigns,
            'gameSystems' => \App\Models\GameSystem::orderBy('name')->get(['id', 'name']),
            'experienceLevels' => ExperienceLevel::cases(),
            'vibeFlagGroups' => VibeFlag::grouped(),
            'languages' => ContentLanguage::cases(),
            'recurrenceOptions' => ['weekly', 'bi-weekly', 'monthly'],
        ]);
    }
}
